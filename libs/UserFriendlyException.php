<?php
declare(strict_types=1);

/**
 * Class UserFriendlyException
 *
 * Eine benutzerfreundliche Exception-Klasse, die von der Standard-Exception erbt.
 * Diese Klasse überschreibt die Standard-Ausgabe, sodass beim Auftreten eines Fehlers
 * nur die eigentliche Fehlermeldung ausgegeben wird – ohne Stacktrace, Datei- oder Zeilenangabe.
 * Dies ist besonders nützlich für Anwendungsfälle, in denen technische Details
 * für den Endanwender verborgen bleiben sollen.
 *
 * Insbesondere in den Visualisierungen von IP-Symcon wird so eine klarere und verständlichere
 * Fehlermeldung angezeigt.
 */

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
