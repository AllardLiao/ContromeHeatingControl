<?php

/**
 * DebugHelper.php
 *
 * Part of the Trait-Libraray for IP-Symcon Modules.
 *
 * @package       traits
 * @author        Kai J. Oey <kai.oey@synergetix.de>
 * @copyright     2025 Kai Oey
 * @link          https://github.com/AllardLiao
 * @license       MIT 
 */

declare(strict_types=1);

/**
 * Helper class for DataFlow Results.
 */
trait ReturnWrapper
{    /**
     * Wrapper for standard return messages
     *
     * Sends $message to debug and Logger.
     * In case success === true LogLevel is KL_DEBUG, if false KL_ERROR
     *
     * @param bool      $success    true|false
     * @param string    $msg        Title of the log message.
     * @param mixed     $payload    any payload you want to use, will be serialized with json_encode!
     */
    protected function wrapReturn(bool $success, string $msg, mixed $payload = null): string
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        $prefix = $success ? "Success" : "Fail";
        $this->SendDebug($caller, "$prefix: $msg", 0);
        $this->LogMessage("$prefix: $msg / $caller", $success ? KL_DEBUG : KL_ERROR);
        return json_encode(['success' => $success, 'message' => $msg, 'payload' => $payload]);
    }

    /**
     * Analyser for standard return messages
     *
     * If result is success, returns true otherwise false
     * If optional params are set, logs messages in debug and logMessage
     * Note: only for 'fail' status, the LogMessage errType is used, in case of 'success' KL_NOTIFY is used.
     *
     * @param string    $result       Return message to be checked and created by wrapReturn!
     * @param string    $errType      IPS error type for LogMessage in case of 'fail'. optional.
     * @param string    $msg          Message to be logged. optional.
     */
    protected function isSuccess(string $result, int $errType = 0, string $msg = ""): bool
    {
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $this->SendDebug(__FUNCTION__, "Invalid JSON: " . substr($result, 0, 200), 0);
            return false;
        }

        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        $prefix = $decoded['success'] ? "Success" : "Fail";

        if ($errType !== 0) {
            $fullMsg = "$prefix: " . ($msg !== "" ? "$msg " : "") . $decoded['message'];
            $this->SendDebug($caller, $fullMsg, 0);
            $this->LogMessage("$fullMsg / $caller", $decoded['success'] ? KL_NOTIFY : $errType);
        }

        return $decoded['success'] === true;
    }

    /**
     * Analyser for standard return messages
     *
     * If result is fail, returns true otherwise false
     * Different to isSuccess only the existence of 'success' => false is checked and respective reply given.
     *
     * @param string    $msg          Return message to be checked and created by wrapReturn!
     * @param string    $errFunction  Function causing the request. optional.
     */
    protected function isError(string $result): bool
    {
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $this->SendDebug(__FUNCTION__, "Invalid JSON for return Wrapper - assuming NO ERROR, instead other data was returned (" . print_r($result, true), 0);
            return false;
        }
        // Only in case we can clearly say "Yes, it is an error", we return true
        return isset($decoded['success']) && ($decoded['success']===false);
    }

    protected function getResponseMessage(string $result): string
    {
        $decoded = json_decode($result, true);
        return $decoded['message'];
    }

    protected function getResponsePayload(string $result): mixed
    {
        $decoded = json_decode($result, true);
        return $decoded['payload'];
    }
}
