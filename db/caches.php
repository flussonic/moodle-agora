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

/**
 * Plugin caches.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Tokens of the Agora accounts: the entry lifetime is set by the login
    // response itself.
    'tokens' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 2,
    ],
    // Broadcast state: the key is the broadcast name, the value is shared by
    // all users.
    'streamstate' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a broadcast name: it may contain a hyphen and a dot.
        'simpledata' => false,
        // No in-process acceleration: the activity page, once it has the lock
        // (`stream_state::for_page()`), re-reads the value that a neighbour
        // process may have just stored — an in-memory copy would return the
        // previous value to it, and it would go to central a second time.
        'staticacceleration' => false,
        'ttl' => 900,
    ],
    // When the activity page last went for the broadcast state — both
    // successfully and with a refusal: no more than one request to central per
    // broadcast per `stream_state::PAGE_FRESH` seconds, even when central
    // refuses.
    'stateattempt' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a broadcast name: it may contain a hyphen and a dot.
        'simpledata' => true,
        'staticacceleration' => false,
        'ttl' => 60,
    ],
    // Deduplication of state refresh orders: one adhoc task per broadcast per
    // minute.
    'staterefresh' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a broadcast name: it may contain a hyphen and a dot.
        'simpledata' => true,
        'staticacceleration' => false,
        'ttl' => 60,
    ],
    // The Agora admission policy (`open_playback`): one per installation, soft
    // staleness of 60 s.
    'policy' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 1,
        'ttl' => 900,
    ],
    // The preview frame of a broadcast: the key is the broadcast name, the
    // value is shared by all users.
    'previews' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a broadcast name: it may contain a hyphen and a dot.
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
        'ttl' => 300,
    ],
    // The archive boundaries of a broadcast: for a stopped broadcast they do
    // not change, so a short lifetime is enough — an invalidation key on
    // `written_until` is not needed.
    'dvrrange' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a broadcast name: it may contain a hyphen and a dot.
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 4,
        'ttl' => 60,
    ],
    // Candidate courses for the side column: both blocks ask for them, and the
    // list of the viewer's courses changes between page views — the cache
    // lives one request.
    'candidates' => [
        'mode' => cache_store::MODE_REQUEST,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 4,
    ],
    // Deduplication of "no frame" log entries: one per broadcast and reason
    // (the reason word and the response code) per hour — the key is
    // `broadcast|reason|code`.
    'previewlog' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a broadcast name: it may contain a hyphen and a dot.
        'simpledata' => true,
        'staticacceleration' => false,
        'ttl' => 3600,
    ],
    // Deduplication of "ticket without address binding" log entries: one per
    // learner per hour — the key is `userid`. The address of a learner behind
    // a broken proxy is not investigated on every issue, and without a limit
    // the log would grow with every activity.
    'grantunboundlog' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => false,
        'ttl' => 3600,
    ],
    // Probe of the recordings facet: whether the recordings endpoints are
    // mounted — a property of the Agora installation, changes on upgrade; a
    // refusal is cached for a long time, a success overwrites a refusal at
    // once.
    'recordrouting' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a broadcast name: it may contain a hyphen and a dot.
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 8,
        'ttl' => 6 * HOURSECS,
    ],
    // The set of vod exports of a recording: read on every page view of the
    // recording, and changes only when the recording closes and the export
    // finishes writing.
    'recordscope' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a recording id: it may contain a hyphen and a dot.
        'simpledata' => false,
        'staticacceleration' => false,
        'ttl' => 300,
    ],
    // Once-only "recording without an export" event: the mark lives while the
    // recording is in this state and is lifted when the export finishes
    // writing.
    'exportlesslog' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false, // The key is a recording id: it may contain a hyphen and a dot.
        'simpledata' => true,
        'staticacceleration' => false,
        'ttl' => 30 * DAYSECS,
    ],
    // Once-only "Agora without the recordings facet" log entry at broadcast
    // start: one mark per activity while the facet is absent.
    'recordfacetlog' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => false,
        'ttl' => 30 * DAYSECS,
    ],
    // Deduplication of "broadcast action failed" events and of the stuck
    // completion escalation: the mark is the activity; a repeat with the same
    // action and reason writes no event, a changed reason writes a new one.
    // Lifted on success.
    'endpendinglog' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => false,
        'ttl' => 30 * DAYSECS,
    ],
];
