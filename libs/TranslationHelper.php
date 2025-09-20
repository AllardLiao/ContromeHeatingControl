<?php
declare(strict_types=1);

/**
 * Translation.php
 *
 * Part of the Allard-Liao Trait-Libraray for IP-Symcon Modules.
 *
 * @package       traits
 * @author        Kai J. Oey <kai.oey@synergetix.de>
 * @copyright     2025 Kai Oey
 * @link          https://github.com/AllardLiao
 * @license       MIT
 */

trait Translation
{
    /**
     * Translates a given string using a translation.json.
     *
     * @param   string  $string     The string to be translated.
     * @return  string              The translated string.
     */
    protected function translate(string $string): string
    {
        return $this->Translate($string);
    }

    protected function loadTranslations(string $language): bool
    {
        $this->LoadTranslations($language);
    }

    protected function addTranslation(string $phrase, string $phraseLanguage, string $phraseTranslation, string $phraseTranslationLanguage): bool
    {
        return $this->addTranslation($phrase, $phraseLanguage, $phraseTranslation, $phraseTranslationLanguage);
    }

    protected function setLanguage(string $language): void
    {
        $this->setLanguage($language);
    }
}
