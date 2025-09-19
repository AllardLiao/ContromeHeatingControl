<?php

/**
 * DebugHelper.php
 *
 * Part of the Trait-Libraray for IP-Symcon Modules.
 *
 * @package       traits
 * @author        Heiko Wilknitz <heiko@wilkware.de>
 * @copyright     2020 Heiko Wilknitz
 * @link          https://wilkware.de
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

declare(strict_types=1);

/**
 * Helper class for the debug output.
 */
trait DebugHelper
{
    /**
     * Adds functionality to serialize arrays and objects.
     *
     * @param string $msg    Title of the debug message.
     * @param mixed  $data   Data output.
     * @param int    $format Output format.
     */
    protected function SendDebug($msg, $data, $format = 0): bool
    {
        if (is_object($data)) {
            foreach ($data as $key => $value) {
                $this->SendDebug($msg . ':' . $key, $value, 1);
            }
            return true;
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $this->SendDebug($msg . ':' . $key, $value, 0);
            }
            return true;
        } elseif (is_bool($data)) {
            return parent::SendDebug($msg, ($data ? 'TRUE' : 'FALSE'), 0);
        } else {
            return parent::SendDebug($msg, $data, $format);
        }
    }

    /**
     * Better print_r variante for debug output.
     *
     * @param mixed $arr Array to print.
     * @return string Pretty formated array data.
     */
    protected function DebugPrint($arr)
    {
        $retStr = '';
        if (is_array($arr)) {
            foreach ($arr as $key=>$val) {
                if (is_array($val)) {
                    $retStr .= '[' . $key . '] => ' . $this->SafePrint($val);
                }else {
                    $retStr .= '[' . $key . '] => ' . $val . ', ';
                }
            }
        }
        return $retStr;
    }

    /**
     * Wrapper to print various object/variable types.
     *
     * @param mixed $var Variable to log
     */
    protected function SafePrint($var)
    {
        if (is_array($var)) {
            // Arrays als JSON-String ausgeben
            return json_encode($var);
        } elseif (is_object($var)) {
            // Objekte als JSON-String ausgeben
            return json_encode($var);
        } elseif (is_bool($var)) {
            // Boolesche Werte als 'true' oder 'false' ausgeben
            return $var ? 'true' : 'false';
        } elseif (is_null($var)) {
            // Null-Werte als 'null' ausgeben
            return 'null';
        } else {
            // Andere Typen direkt ausgeben
            return $var;
        }
    }

    /**
     * Wrapper for default modul log messages
     *
     * @param string $msg  Title of the log message.
     * @param int    $type message typ (KL_DEBUG| KL_ERROR| KL_MESSAGE| KL_NOTIFY (default)| KL_WARNING).
     */
    protected function LogMessage($msg, $type = KL_NOTIFY): bool
    {
        parent::LogMessage($msg, $type);
        return true;
    }

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
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[1]['function'];
        $this->SendDebug($caller, $success ? 'Success: ' : 'Fail: ' . $msg . "(" . $msg . ")", 0);
        $this->LogMessage($success ? 'Success: ' : 'Fail: ' . $msg . "(" . $msg . " / " . $caller . ")", $success ? KL_DEBUG : KL_ERROR);
        return json_encode(['success' => $success, 'message' => $msg, 'payload' => $payload]);
    }

    /**
     * Analyser for standard return messages
     *
     * If result is success, returns true otherwise false
     * If optional params are set, logs messages in debug and logMessage
     * Note: only for 'fail' status, the LogMessage errType is used, in case of 'success' KL_NOTIFY is used.
     *
     * @param string    $msg          Return message to be checked and created by wrapReturn!
     * @param string    $errType      IPS error type for LogMessage in case of 'fail'. optional.
     * @param string    $msg          Message to be logged. optional.
     */
    protected function isSuccess(string $result, int $errType = 0, string $msg = ""): bool
    {
        $decoded = json_decode($result, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        $this->SendDebug(__FUNCTION__, "Decoded result: " . print_r($decoded, true), 0);
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[1]['function'];
        if (isset($decoded['success']) && $decoded['success'] === true) {
            if ($errType <> 0) {
                $this->SendDebug($caller, "Success: " . strlen($msg) > 0 ? ($msg . " ") : "" . $decoded['message'], 0);
                $this->LogMessage("Success: " . strlen($msg) > 0 ? ($msg . " ") : "" . $decoded['message'] . " / " . $caller . ")", KL_NOTIFY);
            }
            return true;
        } else {
            if ($errType <> 0) {
                $this->SendDebug($caller, "Fail: " . strlen($msg) > 0 ? ($msg . " ") : "" . "(" . $decoded['message'] . ")", 0);
                $this->LogMessage("Fail: " . strlen($msg) > 0 ? ($msg . " ") : "" . "(" . $decoded['message'] . " / " . $caller . ")", $errType);
            }
            return false;
        }
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
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        if (empty($decoded['success']) || ($decoded['success']===false))
        {
            return true;
        }
        return false;
    }

    protected function getResponseMessage(string $result): string
    {
        $decoded = json_decode($result, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        return $decoded['message'];
    }

    protected function getResponsePayload(string $result): string
    {
        $decoded = json_decode($result, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        return $decoded['payload'];
    }


}
