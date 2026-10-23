<?php
/**
 * AssistantErrorAdvice.php - turns the technical reason the AI did not answer
 * into a plain explanation and a fix, for the Chat Interface (staff only;
 * customers only ever see the polite fallback reply).
 */
declare(strict_types=1);

namespace Src\Support;

final class AssistantErrorAdvice
{
    /**
     * @param string $reason the stored reason: Flowise error text and/or setup problems
     * @return array{title: string, why: string, fix: string, detail: string}
     */
    public static function explain(string $reason, string $status = 'error'): array
    {
        // "<Flowise error> | Setup: <problems>": the Flowise error decides first.
        [$main, $setup] = array_pad(explode(' | Setup: ', $reason, 2), 2, '');
        foreach (array_filter([$main, $setup]) as $part) {
            $found = self::match(mb_strtolower($part));
            if ($found !== null) {
                return $found + ['detail' => $reason];
            }
        }

        return $status === 'skipped'
            ? ['title' => 'No reply was sent', 'why' => 'The assistant is switched off.', 'fix' => 'Switch it on in Setup and Save.', 'detail' => $reason]
            : ['title' => 'The AI could not answer', 'why' => $reason !== '' ? $reason : 'Unknown error (this message is older than the error tracking).',
               'fix' => 'Open the Flow log (system/logs) for the full details of this message.', 'detail' => $reason];
    }

    /** @return array{title: string, why: string, fix: string}|null */
    private static function match(string $r): ?array
    {
        $rules = [
            ['switched off', 'The AI assistant is switched off',
                'The assistant is turned off, or its settings were never saved, so no reply was sent.',
                'Open the assistant\'s Setup page (AI Assistant Settings), switch it on and click Save.'],
            ['not saved yet', 'The AI assistant settings are not saved',
                'The assistant has no saved settings on this server.',
                'Fill in Settings > Company Profile, then the assistant\'s Setup page (AI Assistant Settings), and Save.'],
            ['cannot be read', 'The saved AI key cannot be read',
                'The AI provider key was saved with a different APP_ENCRYPTION_KEY (for example on another server).',
                'Enter the AI secret key again in the assistant\'s Setup page (AI Assistant Settings) and Save.'],
            ['not configured', 'Flowise is not set up on this server',
                'FLOWISE_API_URL or the chatflow ID is missing.',
                'Add the chatflow ID named in the technical detail to Replit Secrets / .env (Claude: FLOWISE_CHATFLOW_ID_ANTHROPIC, staff: FLOWISE_STAFF_CHATFLOW_ID_ANTHROPIC; Agnes: FLOWISE_CHATFLOW_ID / FLOWISE_STAFF_CHATFLOW_ID), then restart or redeploy.'],
            ['invalid chatflow', 'Wrong chatflow ID',
                'Flowise does not know the chatflow ID the app sent.',
                'Copy only the chatflow ID from Flowise (no https://… and no spaces) into FLOWISE_CHATFLOW_ID / FLOWISE_STAFF_CHATFLOW_ID.'],
            ['http 401', 'Flowise refused the API key',
                'FLOWISE_API_KEY is missing or wrong.',
                'Copy the API key from Flowise > API Keys into FLOWISE_API_KEY (Replit Secret / .env), then restart.'],
            ['http 403', 'Flowise refused the request',
                'The Flowise API key has no access to this chatflow.',
                'Use an API key that is allowed for this chatflow (Flowise > API Keys / chatflow settings).'],
            ['http 404', 'Chatflow not found',
                'The chatflow was deleted or the ID is wrong.',
                'Check the chatflow still exists in Flowise and copy its ID again.'],
            ['temperature` is deprecated', 'The Claude model does not accept a temperature',
                'Newer Claude models reject the "temperature" setting, and the ChatAnthropic node in Flowise still sends one.',
                'In Flowise, open the chatflow, click the ChatAnthropic node > Additional Parameters, clear the Temperature box (leave it empty), and Save. The website no longer sends temperature for Claude.'],
            ['invalid x-api-key', 'The Claude API key is wrong',
                'Anthropic refused the API key.',
                'Paste a valid Claude key (starts with sk-ant-) in the assistant\'s Setup page (AI Assistant Settings), or fix the Anthropic credential in Flowise.'],
            ['credit balance', 'The Claude account has no credit',
                'Anthropic refused the request because the account has no credit left.',
                'Add credit in the Anthropic Console (Plans & Billing), or switch to another AI model.'],
            ['http 5', 'Flowise had an internal error',
                'Flowise or the AI model inside it failed (often a wrong or expired model key in a Flowise credential, or a broken node).',
                'Open the chatflow in Flowise and test it there; check the chat model credential and the Flowise logs on Railway.'],
            ['could not reach', 'Flowise could not be reached',
                'The server could not connect to Flowise, or it took too long to answer.',
                'Check that Flowise (Railway) is running and FLOWISE_API_URL is right. If replies are just slow, raise FLOWISE_TIMEOUT.'],
            ['timed out', 'Flowise took too long',
                'Flowise did not answer in time.',
                'Check Flowise on Railway; raise FLOWISE_TIMEOUT if the model is slow.'],
            ['empty reply', 'The AI returned an empty answer',
                'The model finished without writing any text (this sometimes happens after tool calls).',
                'Usually fine on the next message. If it keeps happening, try another AI model in AI Assistant Settings.'],
        ];

        foreach ($rules as [$needle, $title, $why, $fix]) {
            if (str_contains($r, $needle)) {
                return ['title' => $title, 'why' => $why, 'fix' => $fix];
            }
        }
        return null;
    }
}
