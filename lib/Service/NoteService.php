<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

use OCA\Tickbuddy\Db\Note;
use OCA\Tickbuddy\Db\NoteMapper;
use OCA\Tickbuddy\Db\TrackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;

/**
 * Notes: one short plain text per track per day, stored apart from ticks so
 * unticking never deletes one. Empty text means "no note" everywhere.
 */
class NoteService {
	/** In Unicode code points. The column is TEXT, so this can change without a migration. */
	public const MAX_LENGTH = 500;

	/** How often append() reads and writes again after losing a race, before giving up with 409. */
	private const APPEND_ATTEMPTS = 3;

	public function __construct(
		private NoteMapper $noteMapper,
		private TrackMapper $trackMapper,
		private StrictHandling $strictHandling,
	) {
	}

	/**
	 * A note's text as stored: line breaks made `\n`, then spaces, tabs and line
	 * breaks trimmed at both ends. Refuses text that isn't UTF-8, holds control
	 * characters other than line breaks and tabs, or is too long. Nothing else
	 * is changed: escaping is the job of whoever displays the text.
	 *
	 * @param mixed $text A string; numbers are read as their digits, null as empty
	 * @return string The text to store, '' meaning no note
	 * @throws InvalidValueException
	 */
	public static function normalise(mixed $text): string {
		if ($text === null) {
			return '';
		}
		// A JSON true or false is refused rather than stored as "1" or deleting the note
		if (!is_scalar($text) || is_bool($text)) {
			throw new InvalidValueException('Notes must be UTF-8 text');
		}
		$text = (string)$text;
		if (!mb_check_encoding($text, 'UTF-8')) {
			throw new InvalidValueException('Notes must be UTF-8 text');
		}
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		// C0 controls except \t and \n, DEL, and the C1 controls
		if (preg_match('/[\x{00}-\x{08}\x{0B}-\x{1F}\x{7F}-\x{9F}]/u', $text) === 1) {
			throw new InvalidValueException("Notes can't contain control characters");
		}
		$text = trim($text, " \t\n");
		if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
			throw new InvalidValueException('Notes can be at most ' . self::MAX_LENGTH . ' characters');
		}
		return $text;
	}

	/**
	 * Notes of every track of the user (private and archived included) in a
	 * date range, both ends inclusive, newest first.
	 *
	 * @return Note[]
	 * @throws InvalidValueException if from or to is not YYYY-MM-DD
	 */
	public function findByDateRange(string $userId, string $from, string $to): array {
		if (!TrackService::isValidDate($from) || !TrackService::isValidDate($to)) {
			throw new InvalidValueException('From and to must be dates as YYYY-MM-DD');
		}
		return $this->noteMapper->findByUserAndDateRange($userId, $from, $to);
	}

	/**
	 * Set a day's note; empty text deletes it. On an archived track nothing
	 * changes: a client that asked for strict handling is refused, any other
	 * gets the stored note.
	 *
	 * @return string The stored text, '' if none
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidValueException if the date or the text is not valid
	 * @throws ReadOnlyTrackException if the track is archived and the client asked for strict handling
	 */
	public function set(string $userId, int $trackId, string $date, mixed $text): string {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		self::checkDate($date);
		$text = self::normalise($text);
		if ($track->getArchivedAt() !== null) {
			$this->strictHandling->refuseArchived($track);
			return $this->stored($userId, $trackId, $date);
		}
		return $this->writeForTick($userId, $trackId, $date, $text);
	}

	/**
	 * Add text to a day's note on a new line, or store it if the day has none.
	 * Text whose lines already appear in the note, in order and as whole
	 * lines, is skipped unless `$allowDuplicate`, so a retry or a repeated run
	 * never adds it twice. Empty text changes nothing, and the answer is the
	 * stored note. On an archived track nothing changes either: a client that
	 * asked for strict handling is refused (empty text too), any other gets the
	 * stored note.
	 *
	 * @return string The stored text afterwards, '' if none
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidValueException if the date or the text is not valid, or the result would be too long
	 * @throws NoteConflictException if other writers kept changing the note
	 * @throws ReadOnlyTrackException if the track is archived and the client asked for strict handling
	 */
	public function append(string $userId, int $trackId, string $date, mixed $text, bool $allowDuplicate = false): string {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		self::checkDate($date);
		$text = self::normalise($text);
		if ($track->getArchivedAt() !== null) {
			$this->strictHandling->refuseArchived($track);
			return $this->stored($userId, $trackId, $date);
		}
		return $this->appendForTick($userId, $trackId, $date, $text, $allowDuplicate);
	}

	/**
	 * Check, before a tick call writes anything, that appending would fit the
	 * limit as the note stands now.
	 *
	 * @param string $normalisedText Text from {@see normalise}
	 * @throws InvalidValueException if the result would be too long
	 */
	public function checkAppend(string $userId, int $trackId, string $date, string $normalisedText, bool $allowDuplicate): void {
		if ($normalisedText !== '') {
			self::appended($this->stored($userId, $trackId, $date), $normalisedText, $allowDuplicate);
		}
	}

	/**
	 * Append already checked text (see {@see append}). Every check but the
	 * result's length is the caller's.
	 *
	 * Read, work out the result, then write it only if the note is still what
	 * was read (or, with no note, insert, which the unique index refuses if
	 * another request inserted first). Losing either race reads again. No
	 * transaction, as for TickService::increment: on PostgreSQL a unique
	 * violation would abort it.
	 *
	 * @param string $normalisedText Text from {@see normalise}
	 * @return string The stored text afterwards, '' if none
	 * @throws InvalidValueException if the result would be too long
	 * @throws NoteConflictException if other writers kept changing the note
	 */
	public function appendForTick(string $userId, int $trackId, string $date, string $normalisedText, bool $allowDuplicate): string {
		if ($normalisedText === '') {
			return $this->stored($userId, $trackId, $date);
		}

		for ($attempt = 0; $attempt < self::APPEND_ATTEMPTS; $attempt++) {
			try {
				$note = $this->noteMapper->findByUserTrackDate($userId, $trackId, $date);
			} catch (DoesNotExistException) {
				$note = new Note();
				$note->setUserId($userId);
				$note->setTrackId($trackId);
				$note->setDate($date);
				$note->setText($normalisedText);
				try {
					$this->noteMapper->insert($note);
					return $normalisedText;
				} catch (DbException $e) {
					if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
						throw $e;
					}
					// Another request noted the day in between: append to its note
					continue;
				}
			}

			$oldText = $note->getText();
			$newText = self::appended($oldText, $normalisedText, $allowDuplicate);
			if ($newText === null) {
				return $oldText;
			}
			if ($this->noteMapper->replaceText($note->getId(), $oldText, $newText) > 0) {
				return $newText;
			}
		}
		throw new NoteConflictException('The note changed while appending; try again');
	}

	/**
	 * A note with text appended on a new line; null when every line of the
	 * text already appears in the note, in order and as whole consecutive lines
	 * (case-sensitive), and duplicates aren't allowed.
	 *
	 * @param string $note The stored note, '' if none
	 * @param string $text Non-empty text from {@see normalise}
	 * @throws InvalidValueException if the result would be too long
	 */
	private static function appended(string $note, string $text, bool $allowDuplicate): ?string {
		if ($note === '') {
			return $text;
		}
		if (!$allowDuplicate && self::containsLines(explode("\n", $note), explode("\n", $text))) {
			return null;
		}
		$result = $note . "\n" . $text;
		if (mb_strlen($result, 'UTF-8') > self::MAX_LENGTH) {
			throw new InvalidValueException('The note would be longer than ' . self::MAX_LENGTH . ' characters');
		}
		return $result;
	}

	/**
	 * @param list<string> $lines
	 * @param list<string> $needle
	 */
	private static function containsLines(array $lines, array $needle): bool {
		$length = count($needle);
		for ($i = 0; $i + $length <= count($lines); $i++) {
			if (array_slice($lines, $i, $length) === $needle) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete a day's note, if it has one. On an archived track nothing is
	 * deleted, and a client that asked for strict handling is refused.
	 *
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidValueException if the date is not valid
	 * @throws ReadOnlyTrackException if the track is archived and the client asked for strict handling
	 */
	public function delete(string $userId, int $trackId, string $date): void {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		self::checkDate($date);
		if ($track->getArchivedAt() !== null) {
			$this->strictHandling->refuseArchived($track);
			return;
		}
		$this->noteMapper->deleteByUserTrackDate($userId, $trackId, $date);
	}

	/**
	 * Write an already checked note: replace the day's note, or delete it when
	 * the text is empty. Every check (track, date, text, archived) is the
	 * caller's.
	 *
	 * @param string $normalisedText Text from {@see normalise}
	 * @return string The stored text, '' if none
	 */
	public function writeForTick(string $userId, int $trackId, string $date, string $normalisedText): string {
		if ($normalisedText === '') {
			$this->noteMapper->deleteByUserTrackDate($userId, $trackId, $date);
			return '';
		}

		try {
			$note = $this->noteMapper->findByUserTrackDate($userId, $trackId, $date);
		} catch (DoesNotExistException) {
			$note = new Note();
			$note->setUserId($userId);
			$note->setTrackId($trackId);
			$note->setDate($date);
			$note->setText($normalisedText);
			try {
				$this->noteMapper->insert($note);
				return $normalisedText;
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// Another request noted the day in between: replace its note (last write wins)
				$note = $this->noteMapper->findByUserTrackDate($userId, $trackId, $date);
			}
		}

		$note->setText($normalisedText);
		$this->noteMapper->update($note);
		return $normalisedText;
	}

	/**
	 * The stored note for a day, '' when there is none.
	 */
	public function stored(string $userId, int $trackId, string $date): string {
		try {
			return $this->noteMapper->findByUserTrackDate($userId, $trackId, $date)->getText();
		} catch (DoesNotExistException) {
			return '';
		}
	}

	/**
	 * @throws InvalidValueException
	 */
	private static function checkDate(string $date): void {
		if (!TrackService::isValidDate($date)) {
			throw new InvalidValueException('Date must be a date as YYYY-MM-DD');
		}
	}
}
