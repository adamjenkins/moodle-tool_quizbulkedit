<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_quizbulkedit\local;

/**
 * The outcome of applying one quiz plan.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_result {
    /** @var string the changes were written. */
    public const APPLIED = 'applied';
    /** @var string nothing differed, nothing was written. */
    public const NOCHANGE = 'nochange';
    /** @var string the plan had errors, nothing was written. */
    public const SKIPPED = 'skipped';
    /** @var string writing threw; everything for this quiz was rolled back. */
    public const FAILED = 'failed';

    /**
     * Constructor.
     *
     * @param int $cmid the quiz's course module id
     * @param string $name the quiz name (raw, format it for display)
     * @param string $status one of the constants
     * @param string[] $messages error messages (skipped: the plan's errors; failed: the exception message)
     */
    public function __construct(
        /** @var int the quiz's course module id */
        public readonly int $cmid,
        /** @var string the quiz name (raw) */
        public readonly string $name,
        /** @var string one of the status constants */
        public readonly string $status,
        /** @var string[] error messages */
        public readonly array $messages = [],
    ) {
    }
}
