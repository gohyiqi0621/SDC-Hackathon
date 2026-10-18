<?php
/**
 * onesignal.php - thin server-side client for the OneSignal REST API.
 *
 * Only the server ever calls this, so the REST key never reaches the browser.
 * Transport mirrors app/ai.php: cURL when the extension is present, otherwise
 * an HTTPS stream context (curl is not loaded inside Apache on this machine).
 *
 * Send is one endpoint: POST /notifications, with `send_after` for a schedule
 * and `include_subscription_ids` to target chosen users' devices.
 */
declare(strict_types=1);

/**
 * Append a line to storage/logs/onesignal.log. Never logs the REST key.
 * Mirrors google_log() so both integrations are diagnosed the same way.
 */
function onesignal_log(string $message, array $context = []): void
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    @file_put_contents($dir . '/onesignal.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * The segment used for the "Everyone" audience.
 *
 * Deliberately NOT "Subscribed Users". That is the legacy segment name and does
 * not exist in this app, and OneSignal does not error on an unknown segment -
 * it answers 200 with an EMPTY id plus "All included players are not subscribed",
 * which is indistinguishable from "nobody is subscribed yet". The real default
 * segments are Total / Active / Inactive / Engaged Subscriptions; verified with
 * `GET apps/<id>/segments` and by probing each name (Total and Active accept,
 * Engaged rejects because it requires 30-day engagement).
 */
const ONESIGNAL_ALL_SEGMENT = 'Total Subscriptions';

function onesignal_configured(): bool
{
    return ONESIGNAL_APP_ID !== '' && ONESIGNAL_API_KEY !== '';
}

/**
 * Non-secret diagnostics: which pieces are present. Safe to log - it never
 * contains the App ID or the REST key, only whether each is set and how long
 * the key is.
 *
 * @return array<string,mixed>
 */
function onesignal_config_state(): array
{
    return [
        'source'        => 'database',
        'app_id_set'    => ONESIGNAL_APP_ID !== '',
        'api_key_set'   => ONESIGNAL_API_KEY !== '',
        'key_shape'     => secret_shape(ONESIGNAL_API_KEY),
        'env'           => onesignal_env(),
        'external_prefix' => onesignal_external_prefix(),
    ];
}

/**
 * The external id we hand to OneSignal for one of our users.
 *
 * Namespaced on purpose. OneSignal BLOCKS an external_id that a deleted user
 * once held, and it rejects the create with
 * "external_id is blocked, please use a different ID" - which makes
 * OneSignal.login() fail silently on the client while the SDK still reports the
 * id locally, so every alias-targeted send resolves to nobody and comes back as
 * "All included players are not subscribed". A bare numeric id is the worst
 * case: it collides with ids other integrations have already burned.
 *
 * Prefixing with the app name makes the id ours and keeps it stable across
 * re-subscribes. Change this ONLY in lockstep with what head.php emits.
 */
const ONESIGNAL_EXTERNAL_PREFIX = 'sdc-user-';

/**
 * Prefix used outside production.
 *
 * ONE OneSignal app is shared by every environment (the App ID is seeded into
 * every database, and the REST key is the same), so a subscription created on
 * `localhost/sdc-project/public` lands in the same app as production. With one
 * shared prefix both environments resolve to the same identity, which made a
 * production send reach dev devices - and those show `localhost` as their
 * origin. A separate prefix keeps the two sets of subscriptions apart: the
 * production picker and audience only ever resolve `sdc-user-*`, and a dev
 * device stops matching the moment it loads the app again (OneSignal.login()
 * replaces the alias).
 */
const ONESIGNAL_EXTERNAL_PREFIX_DEV = 'sdc-dev-user-';

/** 'production' or 'dev'. Anything that is not explicitly production is dev. */
function onesignal_env(): string
{
    return APP_ENV === 'production' ? 'production' : 'dev';
}

/** The external-id prefix this environment owns. */
function onesignal_external_prefix(): string
{
    return onesignal_env() === 'production' ? ONESIGNAL_EXTERNAL_PREFIX : ONESIGNAL_EXTERNAL_PREFIX_DEV;
}

function onesignal_external_id(int $userId): string
{
    return onesignal_external_prefix() . $userId;
}

/**
 * Reverse of onesignal_external_id() for a given prefix: 'sdc-user-7' -> 7,
 * anything else -> null.
 *
 * The prefix is a parameter because the parser is also the environment filter:
 * a production lookup passes the production prefix, so a dev subscription (or a
 * legacy shared-prefix one once it reloads) is skipped rather than silently
 * attributed to a production user.
 */
function onesignal_user_id_from_external(string $externalId, ?string $prefix = null): ?int
{
    $prefix ??= onesignal_external_prefix();
    if ($prefix === '' || !str_starts_with($externalId, $prefix)) {
        return null;
    }
    $tail = substr($externalId, strlen($prefix));
    return ctype_digit($tail) ? (int) $tail : null;
}

/** Which auth-key shape we hold, so a wrong key type is obvious in the log. */
function onesignal_key_shape(): string
{
    $k = ONESIGNAL_API_KEY;
    if ($k === '') {
        return 'empty';
    }
    $len = strlen($k);
    // OneSignal REST keys are long opaque strings; legacy keys start with os_v2_.
    return 'len:' . $len . (str_starts_with($k, 'os_v2_') ? ' prefix:os_v2_' : ' prefix:other');
}

/**
 * POST to the OneSignal API. Returns [status, decodedBody|null]; status 0 means
 * transport failure.
 *
 * The cURL extension reports as loaded but is NOT usable on every runtime here
 * (broken inside Apache; fails in CLI too), so a cURL that returns false or
 * status 0 falls back to the stream transport instead of surfacing as a bogus
 * "HTTP 0" rejection. Same rule as onesignal_get().
 *
 * @return array{0:int,1:?array<string,mixed>}
 */
function onesignal_request(string $endpoint, array $payload): array
{
    $url  = 'https://api.onesignal.com/' . ltrim($endpoint, '/');
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Key ' . ONESIGNAL_API_KEY,
    ];

    $via = function_exists('curl_init') ? 'curl' : 'stream';
    onesignal_log('request', [
        'via'       => $via,
        'endpoint'  => $endpoint,
        'key'       => onesignal_key_shape(),
        'app_id'    => ONESIGNAL_APP_ID,
        'payload'   => $payload,
    ]);

    if ($via === 'curl') {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status === 0) {
            onesignal_log('transport failed', ['via' => 'curl', 'error' => $curlErr, 'fallback' => 'stream']);
            return onesignal_post_stream($url, $headers, $body);
        }
    } else {
        return onesignal_post_stream($url, $headers, $body);
    }

    $decoded = json_decode((string) $response, true);
    onesignal_log('response', [
        'status' => $status,
        'body'   => $decoded ?? substr((string) $response, 0, 500),
    ]);

    return [$status, is_array($decoded) ? $decoded : null];
}

/**
 * HTTPS POST through a stream context - the transport that actually works on
 * this machine. Returns [status, decodedBody|null].
 *
 * @param list<string> $headers
 * @return array{0:int,1:?array<string,mixed>}
 */
function onesignal_post_stream(string $url, array $headers, string $body): array
{
    $context = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $body,
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($response === false) {
        $err = error_get_last();
        onesignal_log('transport failed', ['via' => 'stream', 'error' => $err['message'] ?? 'unknown']);
        return [0, null];
    }

    $decoded = json_decode((string) $response, true);
    onesignal_log('response', [
        'status' => $status,
        'body'   => $decoded ?? substr((string) $response, 0, 500),
    ]);

    return [$status, is_array($decoded) ? $decoded : null];
}

/**
 * GET a JSON endpoint (read-only). Same transport switch as the POST path.
 *
 * @return array{0:int,1:?array<string,mixed>}
 */
function onesignal_get(string $endpoint): array
{
    if (!onesignal_configured()) {
        return [0, null];
    }

    $url = 'https://api.onesignal.com/' . ltrim($endpoint, '/');
    $headers = [
        'Accept: application/json',
        'Authorization: Key ' . ONESIGNAL_API_KEY,
    ];

    $via = function_exists('curl_init') ? 'curl' : 'stream';

    if ($via === 'curl') {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        // A cURL that is present but unusable (no CA bundle, blocked outbound)
        // must not silently look like "OneSignal returned nothing" - fall back
        // to the stream transport rather than reporting a false zero.
        if ($response === false || $status === 0) {
            onesignal_log('get transport failed', ['via' => 'curl', 'error' => $err]);
            return onesignal_get_stream($url, $headers);
        }
    } else {
        return onesignal_get_stream($url, $headers);
    }

    $decoded = json_decode((string) $response, true);
    return [$status, is_array($decoded) ? $decoded : null];
}

/**
 * HTTPS GET through a stream context - the transport that actually works here,
 * since Apache's curl extension is loaded but non-functional on this machine.
 *
 * @param list<string> $headers
 * @return array{0:int,1:?array<string,mixed>}
 */
function onesignal_get_stream(string $url, array $headers): array
{
    $context = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($response === false) {
        $err = error_get_last();
        onesignal_log('get transport failed', ['via' => 'stream', 'error' => $err['message'] ?? 'unknown']);
        return [0, null];
    }

    $decoded = json_decode((string) $response, true);
    return [$status, is_array($decoded) ? $decoded : null];
}

/**
 * Which of OUR users can actually receive a push right now.
 *
 * This is the list the composer's recipient picker is built from, and the map
 * send() uses to turn chosen user ids into the subscription ids OneSignal
 * wants. Keyed by our user id, so a user with two browsers counts once and
 * both devices are targeted.
 *
 * Only healthy subscriptions count. A player with `invalid_identifier: true`
 * never received a push endpoint (a browser that was headless, incognito, or
 * otherwise refused push), so it can never be delivered to and must not appear
 * as a choice.
 *
 * Scoped to this environment: a subscription whose alias or `env` tag belongs to
 * another environment is skipped, so a production send can never reach a device
 * that subscribed on localhost.
 *
 * @return array{users:array<int,array{ids:list<string>,devices:int,last_active:int}>,total:int,valid:int,env:string,other_env:int,error:?string}
 */
function onesignal_subscribed_users(): array
{
    // Memoised per request: a send resolves the picker list and then the
    // subscription ids from the same answer, and /players is the slow part.
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $empty = ['users' => [], 'total' => 0, 'valid' => 0, 'env' => onesignal_env(),
        'other_env' => 0, 'error' => null];

    if (!onesignal_configured()) {
        // array_merge, not `+`: the union keeps the LEFT-hand `error => null`,
        // which silently threw the reason away and left every caller believing
        // the lookup had succeeded with nobody subscribed.
        return $cache = array_merge($empty, ['error' => 'OneSignal is not configured.']);
    }

    // /players is paged. A single page used to be enough, but "Everyone" is now
    // resolved from this list instead of a segment, so a silent truncation would
    // quietly stop delivering to the users past the first page.
    $rows   = [];
    $total  = 0;
    $offset = 0;
    $limit  = 200;
    $status = 0;

    for ($page = 0; $page < 5; $page++) {
        [$status, $data] = onesignal_get(
            'players?app_id=' . rawurlencode(ONESIGNAL_APP_ID)
            . '&limit=' . $limit . '&offset=' . $offset . '&device_type=5'
        );

        if ($data === null) {
            return $cache = array_merge($empty, ['error' => 'OneSignal did not answer (HTTP ' . $status . ').']);
        }

        $batch  = $data['players'] ?? [];
        $rows   = array_merge($rows, $batch);
        $total  = (int) ($data['total_count'] ?? count($rows));
        $offset += $limit;

        if (count($batch) < $limit || $offset >= $total) {
            break;
        }
    }

    $prefix = onesignal_external_prefix();
    $env    = onesignal_env();
    $users  = [];
    $valid  = 0;
    $otherEnv = 0;

    foreach ($rows as $p) {
        if (!empty($p['invalid_identifier'])) {
            continue;
        }

        // A subscription tagged for another environment never counts, even if
        // its alias happens to parse - that is what keeps a dev device out of a
        // production audience.
        $tagEnv = $p['tags']['env'] ?? null;
        if (is_string($tagEnv) && $tagEnv !== '' && $tagEnv !== $env) {
            $otherEnv++;
            continue;
        }

        $valid++;

        $userId = onesignal_user_id_from_external(trim((string) ($p['external_user_id'] ?? '')), $prefix);
        $subId  = (string) ($p['id'] ?? '');
        if ($userId === null || $subId === '') {
            continue;
        }

        $users[$userId] ??= ['ids' => [], 'devices' => 0, 'last_active' => 0];
        $users[$userId]['ids'][]     = $subId;
        $users[$userId]['devices']++;
        $users[$userId]['last_active'] = max(
            $users[$userId]['last_active'],
            (int) ($p['last_active'] ?? 0)
        );
    }

    return $cache = [
        'users'     => $users,
        'total'     => $total,
        'valid'     => $valid,
        'env'       => $env,
        'other_env' => $otherEnv,
        'error'     => null,
    ];
}

/**
 * Subscription ids for the given user ids, so a send can name the exact
 * devices instead of an alias.
 *
 * Alias targeting (`include_aliases.external_id`) is deliberately NOT used:
 * OneSignal answers 200 with `errors.invalid_aliases` for an alias it will not
 * resolve, even for a user whose subscriptions are perfectly healthy - which
 * looks exactly like "nobody is subscribed". Subscription ids are unambiguous
 * and were verified to accept both `send_after` and multiple ids.
 *
 * @param  list<int|string>              $userIds
 * @param  array<int,array{ids:list<string>}>|null $map  reuse a previous lookup
 * @return list<string>
 */
function onesignal_subscription_ids(array $userIds, ?array $map = null): array
{
    $map ??= onesignal_subscribed_users()['users'];

    $ids = [];
    foreach ($userIds as $userId) {
        foreach ($map[(int) $userId]['ids'] ?? [] as $subId) {
            $ids[$subId] = true; // de-duplicate across users and devices
        }
    }
    return array_keys($ids);
}

/**
 * Send (or schedule) one push.
 *
 * @param string      $title
 * @param string      $body
 * @param string|null $url        click-through link
 * @param array       $target     ['all'] | ['subscriptions' => list<string>] | ['role' => role]
 * @param string|null $sendAfter  stored wall-clock `Y-m-d H:i:s` to schedule, null to send now
 * @return array{ok:bool,id:?string,recipients:?int,error:string}
 */
function onesignal_send(string $title, string $body, ?string $url, array $target, ?string $sendAfter = null): array
{
    if (!onesignal_configured()) {
        return ['ok' => false, 'id' => null, 'recipients' => null,
            'error' => 'OneSignal is not configured. Add the App ID and REST key on the Key page.'];
    }

    $payload = [
        'app_id'   => ONESIGNAL_APP_ID,
        'headings' => ['en' => $title],
        'contents' => ['en' => $body],
    ];

    if ($url !== null && $url !== '') {
        $payload['url'] = $url;
    }

    // Audience. Explicit subscription ids are the norm; a role uses a tag we set
    // on login. The segment branch is kept for callers that genuinely want the
    // whole app, but the composer no longer uses it: a segment spans every
    // environment, so "Everyone" is resolved to this environment's devices by
    // Notification::targetFor() instead.
    if (isset($target['subscriptions'])) {
        $ids = array_values(array_filter(array_map('strval', (array) $target['subscriptions'])));
        if ($ids === []) {
            // The lookup is memoised, so this costs nothing - and it is the only
            // place the reason (unreachable API, nobody subscribed) can be told
            // apart from "the audience was empty".
            $why = onesignal_subscribed_users();
            $detail = is_string($why['error']) && $why['error'] !== '' ? ' ' . $why['error'] : '';

            return ['ok' => false, 'id' => null, 'recipients' => null,
                'error' => 'No subscribed device was found for that audience, so there is nobody '
                    . 'to notify.' . $detail];
        }
        $payload['include_subscription_ids'] = $ids;
    } elseif (isset($target['role'])) {
        $payload['filters'] = [['field' => 'tag', 'key' => 'role', 'relation' => '=', 'value' => (string) $target['role']]];
    } else {
        $payload['included_segments'] = [ONESIGNAL_ALL_SEGMENT];
    }

    if ($sendAfter !== null && $sendAfter !== '') {
        // Stored as the app's wall clock; OneSignal wants ISO-8601 UTC.
        $payload['send_after'] = schedule_to_iso($sendAfter);
    }

    [$status, $data] = onesignal_request('notifications', $payload);

    // OneSignal reports failures as {"errors":[...]} (a list or a map), and it
    // can return HTTP 200 with an empty id + errors when nothing was sent
    // (e.g. "All included players are not subscribed"). Treat that as a failure.
    $errors     = $data['errors'] ?? null;
    $noDelivery = $data !== null && ($data['id'] ?? '') === ''
        && (($data['recipients'] ?? 0) === 0 || $data['recipients'] === null);

    if ($status < 200 || $status >= 300 || $data === null || $errors !== null || $noDelivery) {
        $message = 'OneSignal rejected the request (HTTP ' . $status . ').';
        if (is_array($errors) && $errors !== []) {
            $flat = [];
            array_walk_recursive($errors, static function ($v) use (&$flat): void {
                if (is_scalar($v)) {
                    $flat[] = (string) $v;
                }
            });
            if ($flat !== []) {
                $message = implode(' ', $flat);
            }
        } elseif (is_string($errors) && $errors !== '') {
            $message = $errors;
        } elseif ($noDelivery) {
            $message = 'OneSignal accepted the request but had nobody to deliver to. '
                . 'No device is subscribed yet — open the app, turn on notifications in Profile, and try again.';
        } elseif (is_string($data['message'] ?? null) && $data['message'] !== '') {
            $message = $data['message'];
        }

        // "not subscribed" is the one error whose real cause is never obvious
        // from the text, so capture who IS reachable at the moment it happens.
        $context = ['status' => $status, 'error' => $message];
        if (str_contains($message, 'not subscribed')) {
            $who = onesignal_subscribed_users();
            $context['audit'] = [
                'total'     => $who['total'],
                'valid'     => $who['valid'],
                'targeting' => $target,
                'reachable' => array_keys($who['users']),
            ];
        }
        onesignal_log('send failed', $context);
        return ['ok' => false, 'id' => null, 'recipients' => null, 'error' => $message];
    }

    onesignal_log('send ok', [
        'id'         => $data['id'] ?? null,
        'recipients' => $data['recipients'] ?? null,
    ]);

    return [
        'ok'         => true,
        'id'         => isset($data['id']) ? (string) $data['id'] : null,
        'recipients' => isset($data['recipients']) ? (int) $data['recipients'] : null,
        'error'      => '',
    ];
}
