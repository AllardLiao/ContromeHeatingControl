<?php

declare(strict_types=1);

class UserFriendlyException extends Exception
{
    public function __construct(string $message, int $code = 0)
    {
        parent::__construct($message, $code, null);
    }

    /**
     * Überschreibt die Standard-Ausgabe.
     * Gibt nur die Meldung zurück – ohne Stacktrace, Datei oder Zeilenangabe.
     */
    public function __toString(): string
    {
        return $this->message;
    }
}
