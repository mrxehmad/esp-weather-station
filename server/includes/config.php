<?php

/*
 * Temp Station - global configuration
 */

const APP_NAME = 'Temp Station';

/*
 * SQLite database file.
 *
 * For best security, move this outside the web root if possible.
 * Example:
 * const DB_FILE = '/home/ahmad/tempstation/data/tempstation.sqlite3';
 */
const DB_FILE = __DIR__ . '/../data/tempstation.sqlite3';

/*
 * PHP timezone used for display helpers.
 * Data is stored in UTC by SQLite.
 */
const TIMEZONE = 'UTC';

/*
 * How many days of readings to keep.
 */
const RETENTION_DAYS = 90;

/*
 * Optional shared secret.
 *
 * If empty, no key is required.
 * If set, the device must send:
 * X-Device-Key: your-secret-key
 */
const DEVICE_KEY = '';
