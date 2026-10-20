<?php

declare(strict_types=1);

namespace OCA\Tickbuddy\Service;

/**
 * The machine-readable `reason` every Tickbuddy error answer carries next to its
 * `message`. Clients branch on these, so they are API: never rename one once
 * released. Nextcloud's own errors (a disabled app's 404 among them) carry none,
 * which is how clients tell them apart from Tickbuddy's verdict.
 */
final class ErrorReason {
	/** 404: the track doesn't exist or isn't the user's */
	public const TRACK_NOT_FOUND = 'trackNotFound';
	/** 400: a parameter is missing or wrong; `message` says which */
	public const INVALID_VALUE = 'invalidValue';
	/** 400: the endpoint doesn't take this track's type */
	public const WRONG_TRACK_TYPE = 'wrongTrackType';
	/** 400: an import with no file, an upload error, or a file over the size limit */
	public const INVALID_UPLOAD = 'invalidUpload';
	/** 400: the import file was read and refused; nothing imported */
	public const IMPORT_FAILED = 'importFailed';
	/** 403: the user already has the most tracks allowed */
	public const TRACK_LIMIT_REACHED = 'trackLimitReached';
	/** 409: other writers kept changing the note while appending */
	public const NOTE_CONFLICT = 'noteConflict';
	/** 409: the database refused a track change */
	public const DATABASE_CONFLICT = 'databaseConflict';
	/** 423: the track is archived (strict clients only) */
	public const ARCHIVED = 'archived';
}
