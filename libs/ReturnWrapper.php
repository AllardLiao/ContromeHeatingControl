<?php
declare(strict_types=1);

/**
 * ReturnWrapper.php
 *
 * Part of the Allard-Liao Trait-Libraray for IP-Symcon Modules.
 *
 * Helper class for communicating results in the DataFlow and internal communication.
 *
 * NOTE: isSuccess and IsError have different purposes:
 * isSuccess checks if the result is a success (true) or not (false) and
 *          in case of false, it is a fail.
 *          it will log messages in debug and logMessage if optional params are set.
 * isError checks if the result is an error (false) or not (true) and
 *          in case of true, it is a fail.
 *          it will NOT log messages in debug and logMessage.
 *          it only checks if the 'success'-Flag => false is set in the JSON string.
 *
 * The difference is that isSuccess expects a JSON encoded string as created by wrapReturn,
 * while isError does NOT expect an JSON encoded string as created by wrapReturn!
 *
 * isError is useful to check if a result is an error, when you do not know
 * if the result is created by wrapReturn or a expected other return set of data!
 *
 * Example:
 *   someFunction is expected to contact an external system and return data.
 *
 *   If the external system is not reachable, it will return a JSON encoded string
 *   created by wrapReturn with 'success' => false. (and if set with a message and payload
 *   and it will have logged the error message in debug and logMessage already).
 *
 *   If the external system is reachable, it will return a JSON encoded string
 *   with the expected data, which does NOT contain the 'success' flag.
 *
 *   In this case, you can use isError to check if the result is an error
 *   without knowing if the result is created by wrapReturn or not.
 *   If you would use isSuccess, it would throw an exception in case the
 *   result is not created by wrapReturn!
 *   This way, you can handle both cases gracefully - and if there is no error,
 *   you can process the expected data.
 *
 * @package       traits
 * @author        Kai J. Oey <kai.oey@synergetix.de>
 * @copyright     2025 Kai Oey
 * @link          https://github.com/AllardLiao
 * @license       MIT
 */

trait ReturnWrapper
{
    private const SALT = "RW88_"; // Prefix to avoid conflicts with other JSON data - change if needed!

    /**
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
        if (!$success)
        {
            $this->SendDebug($caller, "$prefix: $msg - payload: " . print_r($payload, true), 0);
            $this->LogMessage("$prefix: $msg / $caller - payload: " . print_r($payload, true), $success ? KL_DEBUG : KL_ERROR);
        }
        return json_encode([self::SALT . 'success' => $success, self::SALT . 'message' => $msg, self::SALT . 'payload' => $payload]);
    }

    /**
     * Analyser for standard return messages
     *
     * If result is success, returns true otherwise false
     * If optional params are set, logs messages in debug and logMessage.
     * Note: isSuccess expects an JSON encoded string as created by wrapReturn! To check
     * other JSON strings, use isError() instead.
     * Note: only for 'fail' status, the LogMessage errType is used, in case of 'success' KL_NOTIFY is used.
     *
     * @param string    $result             Return message to be checked and has to be created by wrapReturn!
     * @param string    $errType            IPS error type for LogMessage in case of 'fail'. optional.
     * @param string    $msg                Message to be logged. optional.
     * @param bool      $onlyLogOnError     If true, only log on error. optional.
     */
    protected function isSuccess(string $result, int $errType = 0, string $msg = "", bool $onlyLogOnError = false): bool
    {
        $decoded = json_decode($result, true);
        if (!is_array($decoded) ||
            !array_key_exists(self::SALT . 'success', $decoded) ||
            !array_key_exists(self::SALT . 'message', $decoded) ||
            !array_key_exists(self::SALT . 'payload', $decoded)) {
            $this->SendDebug(__FUNCTION__, "Invalid JSON: " . print_r($result, true), 0);
            throw new Exception("Invalid JSON for return Wrapper - please check! (" . print_r($result, true) . ")");
        }

        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        $prefix = $decoded[self::SALT . 'success'] ? "Success" : "Fail";

        if ($errType !== 0) {
            $fullMsg = "$prefix: " . ($msg !== "" ? "$msg " : "") . $decoded[self::SALT . 'message'];
            $this->SendDebug($caller, $fullMsg, 0);
            $this->LogMessage("$fullMsg / $caller", $decoded[self::SALT . 'success'] ? KL_NOTIFY : $errType);
        }

        return $decoded[self::SALT . 'success'] === true;
    }

    /**
     * Analyser for standard return messages
     *
     * If result is fail, returns true otherwise false
     * Different to isSuccess only the existence of 'success' => false is checked and respective reply given.
     * Note: isError does NOT expect an JSON encoded string as created by wrapReturn!
     *
     * @param string    $msg          Return message to be checked and created by wrapReturn!
     * @param string    $errFunction  Function causing the request. optional.
     */
    protected function isError(string $result): bool
    {
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $this->SendDebug(__FUNCTION__, "Invalid JSON for return Wrapper, instead other data was returned (" . print_r($result, true) . ")", 0);
            return false;
        }
        // Only in case we can clearly say "Yes, it is an error", we return true
        return isset($decoded[self::SALT . 'success']) && ($decoded[self::SALT . 'success']===false);
    }

    protected function getResponseMessage(string $result): string
    {
        $decoded = json_decode($result, true);
        return $decoded[self::SALT . 'message'];
    }

    protected function getResponsePayload(string $result): mixed
    {
        $decoded = json_decode($result, true);
        return $decoded[self::SALT . 'payload'];
    }
}
