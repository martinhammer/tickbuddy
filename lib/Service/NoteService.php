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
	public const MAX_LENGTH = 200;

	public function __construct(
		private NoteMapper $noteMapper,
		private TrackMapper $trackMapper,
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
	 * changes and the answer is the stored note.
	 *
	 * @return string The stored text, '' if none
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidValueException if the date or the text is not valid
	 */
	public function set(string $userId, int $trackId, string $date, mixed $text): string {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		self::checkDate($date);
		$text = self::normalise($text);
		if ($track->getArchivedAt() !== null) {
			return $this->stored($userId, $trackId, $date);
		}
		return $this->writeForTick($userId, $trackId, $date, $text);
	}

	/**
	 * Delete a day's note, if it has one. On an archived track nothing is deleted.
	 *
	 * @throws DoesNotExistException if track not found
	 * @throws InvalidValueException if the date is not valid
	 */
	public function delete(string $userId, int $trackId, string $date): void {
		$track = $this->trackMapper->findByIdAndUser($trackId, $userId);
		self::checkDate($date);
		if ($track->getArchivedAt() !== null) {
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
