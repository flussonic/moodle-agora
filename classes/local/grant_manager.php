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

namespace mod_agora\local;

/**
 * Viewing tickets: one live ticket per "learner + activity" pair.
 *
 * A new ticket is requested from central only when the previous one is
 * missing, expired, revoked or issued for a different learner address. The
 * `user_id` value is assembled once and later taken from the table: renaming
 * a person in Moodle must not break their viewing history.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grant_manager {
    /** The ticket purpose: viewing by a learner. */
    const PURPOSE_VIEW = 'view';

    /** The ticket purpose: the service preview frame. */
    const PURPOSE_PREVIEW = 'preview';

    /** The ticket purpose: publishing from the browser (WHIP). Issuing is owned by the publisher slice. */
    const PURPOSE_PUBLISH = 'publish';

    /** The lifetime of the service preview ticket, seconds. */
    const PREVIEW_TTL = 3600;

    /**
     * How many screens the stream service ticket has.
     *
     * The node counts a frame request and an archive bounds request as a
     * session by the "address + ticket" pair (confirmed on the stand on
     * 30.09.2026: two `dvr-range` requests from two addresses under one ticket
     * are two active sessions, and central with `max_concurrent: 1` closes the
     * older one). Every web node and cron node of Moodle arrives from its own
     * address, so one screen means Moodle nodes warring with each other. Ten
     * is with a margin for a Moodle farm; "unlimited" is forbidden by the
     * spec: a service ticket must not become boundless.
     */
    const SERVICE_MAX_CONCURRENT = 10;

    /** How many seconds to wait while a neighbouring process issues the service ticket. */
    const SERVICE_LOCK_WAIT = 5;

    /**
     * Placeholder addresses of "unknown from where".
     *
     * `getremoteaddr()` returns `0.0.0.0` when the address is missing or not
     * parsed; `cleanremoteaddr()` writes IPv6 uncompressed, so "::" here is
     * expanded.
     */
    const UNKNOWN_ADDRESSES = ['0.0.0.0', '0:0:0:0:0:0:0:0'];

    /**
     * The clock skew margin, seconds.
     *
     * `expires_at` is computed by central, but compared against Moodle's
     * `time()`: a ticket with one second left may already be dead at central.
     * It counts as live only if more than this margin remains.
     */
    const CLOCK_MARGIN = 60;

    /**
     * The live ticket of the pair: reuses the previous one or requests a new one.
     *
     * The previous ticket is good only if address binding is off or its
     * address matches the learner address. Otherwise the ticket is revoked at
     * central and redeemed: a forwarded link must die together with the
     * address change, not live out its term.
     *
     * @param \stdClass $agora the activity record
     * @param int $userid learner
     * @param int $courseid course
     * @param int $cmid course module
     * @param string $clientip the learner address as Moodle sees it (`getremoteaddr()`)
     * @return \stdClass the `agora_grants` row with the `token` field
     */
    public static function for_user(
        \stdClass $agora,
        int $userid,
        int $courseid,
        int $cmid,
        string $clientip
    ): \stdClass {
        global $DB;

        $unusable = false;
        $bindip = self::bind_address($clientip, $unusable);

        self::mark_expired($agora->id, $userid, self::PURPOSE_VIEW);

        $live = $DB->get_records_select(
            'agora_grants',
            'agoraid = :agoraid AND userid = :userid AND purpose = :purpose AND status = :status AND expires_at > :now',
            [
                'agoraid' => $agora->id,
                'userid' => $userid,
                'purpose' => self::PURPOSE_VIEW,
                'status' => 'issued',
                'now' => time() + self::CLOCK_MARGIN,
            ],
            'expires_at DESC',
            '*',
            0,
            1
        );
        if ($live) {
            $row = reset($live);
            if (!self::binding_on() || (string) ($row->bind_ip ?? '') === (string) $bindip) {
                $desired = recording_scope::of($agora);
                // Tickets older than the `scope` column do not have it: we
                // count their scope as the stream name — no needless reissue.
                $current = $row->scope !== null
                    ? recording_scope::decode((string) $row->scope)
                    : [(string) $agora->stream_name];
                if ($current === $desired) {
                    return $row;
                }
                // The scope diverges (a recording export appeared): the new
                // ticket first, the revocation of the old one after it — the
                // live sessions of the old `jti` will be closed by the ban
                // feed (D8).
                $grant = self::issue($agora, $userid, $courseid, $cmid, $bindip);
                self::revoke_where(['id' => (int) $row->id]);
                return $grant;
            }
            // The address changed: revoke the previous ticket; a central
            // refusal does not prevent issuing a new one — it is logged inside
            // `revoke_where`.
            self::revoke_where(['id' => (int) $row->id]);
        }

        $grant = self::issue($agora, $userid, $courseid, $cmid, $bindip);
        $dedup = \cache::make('mod_agora', 'grantunboundlog');
        if ($unusable && !$dedup->get($userid)) {
            // Binding is on, but the learner address is unusable: the ticket
            // was issued without it. This is a warning for the administrator —
            // it is fixed with the Moodle settings `reverseproxy` and
            // `getremoteaddrconf` — and it lives in the event log next to the
            // ticket issue entry. One entry per learner per hour, like the
            // "no frame" one: behind a broken proxy the address fails to parse
            // on every issue.
            $dedup->set($userid, time());
            \mod_agora\event\grant_unbound::create([
                'objectid' => (int) $grant->id,
                'context' => \context_module::instance($cmid),
                'relateduserid' => $userid ?: null,
                'other' => [
                    'jti' => (string) $grant->jti,
                    'address' => \core_text::substr(clean_param($clientip, PARAM_NOTAGS), 0, 64),
                ],
            ])->trigger();
        }
        return $grant;
    }

    /**
     * Whether ticket binding to the learner address is on.
     *
     * @return bool
     */
    private static function binding_on(): bool {
        return !empty(get_config('mod_agora', 'bindip'));
    }

    /**
     * The ticket binding address: the normalized learner address or null.
     *
     * Normalization is the core `cleanremoteaddr()`: the same IPv6 record in
     * different forms yields one string, and the comparison with the ticket
     * address does not lie. An address that does not parse as an IP does not
     * become a binding: a ticket with garbage in `bind_ip` would not play from
     * any address, and the learner would see nothing. Same for "address
     * unknown" — `getremoteaddr()` returns `0.0.0.0` instead of an unparsed
     * address, and binding to it would be the same silent breakage. The cause
     * of a ticket without binding goes to the event log as a `grant_unbound`
     * entry — `for_user()` writes it when the ticket is issued.
     *
     * @param string $clientip the learner address as Moodle sees it
     * @param bool $unusable "binding is on, but the address is unusable" is written here
     * @return string|null the binding address or null when there will be no binding
     */
    private static function bind_address(string $clientip, bool &$unusable): ?string {
        $unusable = false;
        if (!self::binding_on()) {
            return null;
        }
        $address = cleanremoteaddr($clientip);
        if ($address === null || $address === '' || in_array($address, self::UNKNOWN_ADDRESSES, true)) {
            $unusable = true;
            return null;
        }
        return $address;
    }

    /**
     * Marks the expired tickets of the pair and wipes their secret.
     *
     * @param int $agoraid activity
     * @param int $userid learner
     * @param string $purpose ticket purpose
     * @return void
     */
    public static function mark_expired(int $agoraid, int $userid, string $purpose): void {
        global $DB;
        $DB->execute(
            "UPDATE {agora_grants} SET status = 'expired', token = NULL
                  WHERE agoraid = ? AND userid = ? AND purpose = ? AND status = 'issued' AND expires_at <= ?",
            [$agoraid, $userid, $purpose, time() + self::CLOCK_MARGIN]
        );
    }

    /**
     * Requests a new ticket from central and stores it.
     *
     * @param \stdClass $agora the activity record
     * @param int $userid learner
     * @param int $courseid course
     * @param int $cmid course module
     * @param string|null $bindip the binding address or null when the ticket is unbound
     * @return \stdClass the stored `agora_grants` row
     */
    private static function issue(
        \stdClass $agora,
        int $userid,
        int $courseid,
        int $cmid,
        ?string $bindip
    ): \stdClass {
        global $DB;

        connection::require_label('opcreategrant', 'player');

        $userref = self::user_ref($agora->id, $userid);
        $ttl = (int) get_config('mod_agora', 'grantttl') ?: 7200;
        $maxconcurrent = (int) get_config('mod_agora', 'maxconcurrent') ?: 2;
        // The scope: the stream and the vod identities of the bound
        // recording's exports — with one ticket, because the resolver may
        // switch the source mid-viewing.
        $scope = recording_scope::of($agora);

        $body = [
            'streams' => array_values($scope),
            'user_id' => $userref,
            'ttl_secs' => $ttl,
            'max_concurrent' => $maxconcurrent,
            'note' => 'moodle course=' . $courseid . ' cmid=' . $cmid,
        ];
        if ($bindip !== null) {
            $body['bind_ip'] = $bindip;
        }

        $answer = connection::client()->create_grant($body);

        $record = (object) [
            'agoraid' => (int) $agora->id,
            'userid' => $userid,
            'purpose' => self::PURPOSE_VIEW,
            'stream_name' => (string) $agora->stream_name,
            'jti' => (string) ($answer['jti'] ?? ''),
            'token' => (string) ($answer['token'] ?? ''),
            'user_id' => $userref,
            'bind_ip' => $bindip,
            'scope' => recording_scope::encode($scope),
            'expires_at' => self::parse_expiry($answer['expires_at'] ?? null, $ttl),
            'status' => 'issued',
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('agora_grants', $record);
        watch::note_grant((int) $agora->id, $userid, $userref, (int) $record->expires_at);

        \mod_agora\event\grant_issued::create([
            'objectid' => $record->id,
            'context' => \context_module::instance($cmid),
            'relateduserid' => $userid ?: null,
            'other' => ['jti' => $record->jti, 'stream' => $agora->stream_name],
        ])->trigger();

        return $record;
    }

    /**
     * The stream service ticket: the Moodle server takes the preview frame and
     * the archive bounds with it.
     *
     * The scope is exactly one stream name, up to `SERVICE_MAX_CONCURRENT`
     * screens, an hour of life, no address binding. Keyed by the stream name,
     * not the activity: one stream may sit in several activities, and there
     * must not be more service sessions because of that.
     *
     * Issuing goes under a lock on the stream: with a cold cache a hundred
     * learners opening recordings of one stream will issue one service ticket,
     * not a hundred. Whoever does not get the lock receives an `AG-BUSY`
     * refusal — the page draws the player without bounds, and the preview does
     * without a frame.
     *
     * @param string $streamname stream name
     * @return \stdClass the `agora_grants` row with the `token` field
     * @throws agora_exception when the ticket is not issued
     */
    public static function for_preview(string $streamname): \stdClass {
        $live = self::live_service($streamname);
        if ($live !== null) {
            return $live;
        }

        $factory = \core\lock\lock_config::get_lock_factory('mod_agora_servicegrant');
        $lock = $factory->get_lock(sha1($streamname), self::SERVICE_LOCK_WAIT);
        if (!$lock) {
            $live = self::live_service($streamname);
            if ($live !== null) {
                return $live;
            }
            // Not an Agora refusal: Moodle itself did not wait out its own
            // lock. The operation is named so that the log does not write
            // "unknown operation".
            $error = new agora_exception('errservicebusy', null, 'busy');
            $error->operation = 'opissueservice';
            $error->role = 'service';
            throw $error;
        }
        try {
            // While we waited for the lock, a neighbouring process may have
            // already issued the ticket.
            $live = self::live_service($streamname);
            if ($live !== null) {
                return $live;
            }
            return self::issue_service($streamname);
        } finally {
            $lock->release();
        }
    }

    /**
     * Redeems the stream service ticket rejected by the node (401/403).
     *
     * Exactly the ticket that was presented is redeemed — by `jti`: a
     * neighbouring process may have already issued a fresh one, and it must
     * not be redeemed. The next request will issue a new one. Without this the
     * rejected ticket would live in the table until the end of its hour, and
     * every recording view would get a refusal.
     *
     * @param string $streamname stream name
     * @param string $jti identifier of the rejected ticket
     * @return void
     */
    public static function forget_service(string $streamname, string $jti): void {
        global $DB;

        if ($jti === '') {
            return;
        }
        $DB->execute(
            "UPDATE {agora_grants} SET status = 'expired', token = NULL
                  WHERE purpose = ? AND stream_name = ? AND status = 'issued' AND jti = ?",
            [self::PURPOSE_PREVIEW, $streamname, $jti]
        );
    }

    /**
     * The live stream service ticket from the table or null; expired ones are redeemed.
     *
     * @param string $streamname stream name
     * @return \stdClass|null
     */
    private static function live_service(string $streamname): ?\stdClass {
        global $DB;

        $DB->execute(
            "UPDATE {agora_grants} SET status = 'expired', token = NULL
                  WHERE purpose = ? AND stream_name = ? AND status = 'issued' AND expires_at <= ?",
            [self::PURPOSE_PREVIEW, $streamname, time() + self::CLOCK_MARGIN]
        );

        $live = $DB->get_records_select(
            'agora_grants',
            'purpose = :purpose AND stream_name = :stream AND status = :status AND expires_at > :now',
            [
                'purpose' => self::PURPOSE_PREVIEW,
                'stream' => $streamname,
                'status' => 'issued',
                'now' => time() + self::CLOCK_MARGIN,
            ],
            'expires_at DESC',
            '*',
            0,
            1
        );
        return $live ? reset($live) : null;
    }

    /**
     * Issues the stream service ticket and puts it into the table.
     *
     * @param string $streamname stream name
     * @return \stdClass
     */
    private static function issue_service(string $streamname): \stdClass {
        global $DB;

        connection::require_label('opcreategrant', 'player');
        $userref = connection::label() . ':service:preview';
        $answer = connection::client()->create_grant([
            'streams' => [$streamname],
            'user_id' => $userref,
            'ttl_secs' => self::PREVIEW_TTL,
            'max_concurrent' => self::SERVICE_MAX_CONCURRENT,
            'note' => 'moodle service: preview, archive range',
        ]);

        $record = (object) [
            'agoraid' => 0,
            'userid' => 0,
            'purpose' => self::PURPOSE_PREVIEW,
            'stream_name' => $streamname,
            'jti' => (string) ($answer['jti'] ?? ''),
            'token' => (string) ($answer['token'] ?? ''),
            'user_id' => $userref,
            // The service ticket deliberately knows no binding: it is
            // presented by the Moodle server, not by a learner.
            'bind_ip' => null,
            'expires_at' => self::parse_expiry($answer['expires_at'] ?? null, self::PREVIEW_TTL),
            'status' => 'issued',
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('agora_grants', $record);
        return $record;
    }

    /**
     * The publishing ticket for WHIP broadcasting from the browser.
     *
     * Issued by the separate account `lms-publisher` (a bridge to the
     * admission kind separation in Agora): a viewer ticket does not authorize
     * publishing. The lifetime is the site setting, 4 hours by default; a live
     * ticket is reused while more than half of its term remains. When the term
     * nears its end, the publisher page gets a new ticket, and the previous
     * one is NOT revoked: it lives out its term naturally. A revocation here
     * would break a live WHIP session with the ban feed of the old `jti`
     * (review R2, Б-1) — the pulse must continue broadcasting, not tear it.
     * Publish tickets are revoked only by ending the broadcast and deleting
     * the activity.
     *
     * The right is checked by the caller before the call: the entry points are
     * the publisher page and the ticket external function, both under
     * `mod/agora:broadcast` (D11).
     *
     * @param \stdClass $agora the activity record
     * @param int $userid presenter
     * @param int $cmid course module
     * @param string $clientip the presenter address as Moodle sees it (`getremoteaddr()`)
     * @return \stdClass the `agora_grants` row with the `token` field
     */
    public static function for_publish(\stdClass $agora, int $userid, int $cmid, string $clientip): \stdClass {
        global $DB;

        connection::require_label('opcreategrant', 'publisher');

        $unusable = false;
        $bindip = self::bind_address($clientip, $unusable);
        $ttl = (int) get_config('mod_agora', 'publishticketttl') ?: 4 * HOURSECS;

        self::mark_expired((int) $agora->id, $userid, self::PURPOSE_PUBLISH);

        $live = $DB->get_records_select(
            'agora_grants',
            'agoraid = :agoraid AND userid = :userid AND purpose = :purpose AND status = :status AND expires_at > :now',
            [
                'agoraid' => $agora->id,
                'userid' => $userid,
                'purpose' => self::PURPOSE_PUBLISH,
                'status' => 'issued',
                'now' => time(),
            ],
            'expires_at DESC'
        );
        $freshest = null;
        foreach ($live as $row) {
            if (self::binding_on() && (string) ($row->bind_ip ?? '') !== (string) $bindip) {
                // The address changed: revoke the previous one, like the
                // viewer ones. This is not a pulse but a binding change — a
                // live WHIP from this address would fail on bind_ip anyway.
                self::revoke_where(['id' => (int) $row->id]);
                continue;
            }
            if ($freshest === null) {
                $freshest = $row;
            }
        }
        if ($freshest !== null && (int) $freshest->expires_at > time() + intdiv($ttl, 2)) {
            return $freshest;
        }

        // Reissue: the new ticket is issued on top, the old one lives out its
        // term — the pulse does not tear the broadcast (see the method
        // description).
        return self::issue_publish($agora, $userid, $cmid, $bindip, $ttl);
    }

    /**
     * Issues a new publishing ticket from the `lms-publisher` account.
     *
     * @param \stdClass $agora the activity record
     * @param int $userid presenter
     * @param int $cmid course module
     * @param string|null $bindip the binding address or null
     * @param int $ttl ticket lifetime, seconds
     * @return \stdClass the stored `agora_grants` row
     */
    private static function issue_publish(
        \stdClass $agora,
        int $userid,
        int $cmid,
        ?string $bindip,
        int $ttl
    ): \stdClass {
        global $DB;

        $username = (string) $DB->get_field('user', 'username', ['id' => $userid], IGNORE_MISSING);
        $userref = connection::label() . ':publish:' . $userid . ':' . $username;

        $body = [
            'streams' => [(string) $agora->stream_name],
            'user_id' => $userref,
            'ttl_secs' => $ttl,
            // Broadcasting — one session per ticket: a repeated sign-in with
            // the same ticket would mean the old session was not closed.
            'max_concurrent' => 1,
            'note' => 'moodle publish cmid=' . $cmid,
        ];
        if ($bindip !== null) {
            $body['bind_ip'] = $bindip;
        }

        $answer = connection::client()->create_grant($body, 'publisher');

        $record = (object) [
            'agoraid' => (int) $agora->id,
            'userid' => $userid,
            'purpose' => self::PURPOSE_PUBLISH,
            'stream_name' => (string) $agora->stream_name,
            'jti' => (string) ($answer['jti'] ?? ''),
            'token' => (string) ($answer['token'] ?? ''),
            'user_id' => $userref,
            'bind_ip' => $bindip,
            'scope' => recording_scope::encode([(string) $agora->stream_name]),
            'expires_at' => self::parse_expiry($answer['expires_at'] ?? null, $ttl),
            'status' => 'issued',
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('agora_grants', $record);

        \mod_agora\event\publish_ticket_issued::create([
            'objectid' => (int) $record->id,
            'context' => \context_module::instance($cmid),
            'relateduserid' => $userid ?: null,
            'other' => ['stream' => (string) $agora->stream_name, 'jti' => (string) $record->jti],
        ])->trigger();

        return $record;
    }

    /**
     * The `user_id` value of the pair: from the previous ticket or assembled for the first time.
     *
     * @param int $agoraid activity
     * @param int $userid learner
     * @return string `<label>:<userid>:<login>`
     */
    public static function user_ref(int $agoraid, int $userid): string {
        global $DB;

        // The pair row lives longer than the tickets (the task deletes them a
        // day after expiry): the `user_id` is not reassembled from it even
        // after a renaming.
        $stored = $DB->get_field('agora_watch', 'user_ref', ['agoraid' => $agoraid, 'userid' => $userid]);
        if ((string) $stored !== '') {
            return (string) $stored;
        }

        $known = $DB->get_field_select(
            'agora_grants',
            'user_id',
            'agoraid = :agoraid AND userid = :userid AND purpose = :purpose AND '
                . $DB->sql_isnotempty('agora_grants', 'user_id', false, false),
            ['agoraid' => $agoraid, 'userid' => $userid, 'purpose' => self::PURPOSE_VIEW],
            IGNORE_MULTIPLE
        );
        if (!empty($known)) {
            return $known;
        }

        $username = (string) $DB->get_field('user', 'username', ['id' => $userid], IGNORE_MISSING);
        return connection::label() . ':' . $userid . ':' . $username;
    }

    /**
     * The ticket lifetime from the central response; for an empty response — by the requested `ttl_secs`.
     *
     * @param mixed $value the `expires_at` value from the response
     * @param int $ttl the requested lifetime in seconds
     * @return int unix time
     */
    private static function parse_expiry($value, int $ttl): int {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return time() + $ttl;
    }

    /**
     * Revokes the learner's live tickets for the course streams.
     *
     * @param int $courseid course
     * @param int $userid learner
     * @return int how many tickets were revoked
     */
    public static function revoke_for_course(int $courseid, int $userid): int {
        global $DB;

        if (!$courseid || !$userid) {
            return 0;
        }
        $ids = $DB->get_fieldset_select('agora', 'id', 'course = ?', [$courseid]);
        $done = 0;
        foreach ($ids as $agoraid) {
            $done += self::revoke_where([
                'agoraid' => (int) $agoraid,
                'userid' => $userid,
                'purpose' => self::PURPOSE_VIEW,
            ]);
        }
        return $done;
    }

    /**
     * Revokes the tickets of those course activities that became unavailable to the learner.
     *
     * The occasion is a removed group or role: the event itself does not say
     * whether the access is gone, so `uservisible` and the activity right are
     * re-read.
     *
     * @param int $courseid course
     * @param int $userid learner
     * @return int how many tickets were revoked
     */
    public static function revoke_lost_access(int $courseid, int $userid): int {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || !$userid) {
            return 0;
        }

        $done = 0;
        $modinfo = get_fast_modinfo($course, $userid);
        foreach ($modinfo->get_instances_of('agora') as $cm) {
            $context = \context_module::instance($cm->id);
            $allowed = $cm->uservisible && has_capability('mod/agora:view', $context, $userid);
            if ($allowed) {
                continue;
            }
            $done += self::revoke_where([
                'agoraid' => (int) $cm->instance,
                'userid' => $userid,
                'purpose' => self::PURPOSE_VIEW,
            ]);
        }
        return $done;
    }

    /**
     * The context in which the event log will show the ticket refusal.
     *
     * The activity context if the activity still exists; otherwise (a service
     * ticket, the activity already deleted) — the system one: an entry about
     * an unrevoked ticket matters more than its place in the context tree.
     *
     * @param \stdClass $row an `agora_grants` row
     * @return \context
     */
    private static function grant_context(\stdClass $row): \context {
        $cm = !empty($row->agoraid)
            ? get_coursemodule_from_instance('agora', (int) $row->agoraid, 0, false, IGNORE_MISSING)
            : false;
        $context = $cm ? \context_module::instance((int) $cm->id, IGNORE_MISSING) : false;
        return $context ?: \context_system::instance();
    }

    /**
     * Revokes live tickets by a condition and marks them `revoked`.
     *
     * @param array $conditions selection conditions for `agora_grants` rows
     * @return int how many tickets were revoked
     */
    public static function revoke_where(array $conditions): int {
        global $DB;

        $conditions['status'] = 'issued';
        $rows = $DB->get_records('agora_grants', $conditions);
        $done = 0;
        foreach ($rows as $row) {
            try {
                connection::client()->revoke_grant($row->jti);
                $done++;
            } catch (agora_exception $e) {
                // A revocation refusal must not break the Moodle operation:
                // the row is redeemed anyway, and the refusal is written to
                // the event log — the ticket in Agora then lives out its term,
                // and the administrator must see it (the `oprevokegrant`
                // operation, the `player` account).
                failure::log($e, self::grant_context($row), 'oprevokegrant', 'player');
            }
            $DB->set_field('agora_grants', 'token', null, ['id' => $row->id]);
            $DB->set_field('agora_grants', 'status', 'revoked', ['id' => $row->id]);
        }
        return $done;
    }
}
