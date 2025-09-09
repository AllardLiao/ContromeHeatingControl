<?php

/**
 * WidgetHelper.php
 *
 * Part of the Trait-Libraray for IP-Symcon Modules.
 *
 * @package       traits
 * @author        Heiko Wilknitz <heiko@wilkware.de>
 * @copyright     2025 Heiko Wilknitz
 * @link          https://wilkware.de
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

declare(strict_types=1);

/**
 * Helper class for openHASP widgets.
 */
trait WidgetHelper
{
    /**
     * Sets the hands of an analouge clock.
     *
     * @param int $page Page ID
     * @param int $hour Hour hand object ID
     * @param int $minute Minute hand object ID
     */
    protected function AnalougeClock(int $page, int $hour, int $minute)
    {
        // Current time
        $h = date('H');      // Actual hour (00 - 23)
        $m = date('i');    // Actual minute (00 - 59)

        // hour hand
        $hand = ($h * 60) + $m;
        if ($h >= 12) {
            $hand = (($h - 12) * 60) + $m;
        }
        $this->SetItemValue($page, $hour, intval($hand));
        $this->SetItemValue($page, $minute, intval($m));
    }

    /**
     * Sets the time of an flip clock.
     *
     * @param int $page Page ID
     * @param int $hour Hour flip card start object ID
     * @param int $minute Minute flip card start object ID
     */
    protected function FlipClock(int $page, int $hour, int $minute)
    {
        // Current time
        $h = date('H');      // Actual hour (00 - 23)
        $m = date('i');    // Actual minute (00 - 59)

        // hour flip
        $this->SetItemText($page, $hour++, $h[0]);
        $this->SetItemText($page, $hour++, $h[0]);
        $this->SetItemText($page, $hour++, $h[1]);
        $this->SetItemText($page, $hour++, $h[1]);
        // minute flip
        $this->SetItemText($page, $minute++, $m[0]);
        $this->SetItemText($page, $minute++, $m[0]);
        $this->SetItemText($page, $minute++, $m[1]);
        $this->SetItemText($page, $minute++, $m[1]);
    }

    /**
     * Sets the time of an hasp circle clock.
     *
     * @param int $page Page ID
     * @param int $minute Minute circle arc ID
     */
    protected function HaspClock(int $page, int $minute)
    {
        // Current time
        $m = date('i');    // Actual minute (00 - 59)
        $val = intval(($m * 100) / 60);
        // minute flip
        $this->SetItemValue($page, $minute++, $val);
    }

    /**
     * Sets the time of an Qlocktwo eartch clock.
     *
     * @param int $page Page ID
     * @param int $row Start row ID
     * @param int $color Color of the marked words
     * @param bool $prefix Show prefix if true, otherwise not.
     * @param bool $suffix Show sufffix if true, otherwise not.
     * @param bool $clock Show always the word 'UHRÄ if true, otherwise not.
     */
    protected function QlocktwoEarth(int $page, int $row, int $color, bool $prefix = true, $suffix = true, $clock = true)
    {
        // Die 10 horizontalen Reihen der QLOCKTWO
        $qlocktwo = [
            ['E', 'S', 'K', 'I', 'S', 'T', 'L', 'F', '\u00dc', 'N', 'F'],
            ['Z', 'E', 'H', 'N', 'Z', 'W', 'A', 'N', 'Z', 'I', 'G'],
            ['D', 'R', 'E', 'I', 'V', 'I', 'E', 'R', 'T', 'E', 'L'],
            ['T', 'G', 'N', 'A', 'C', 'H', 'V', 'O', 'R', 'J', 'M'],
            ['H', 'A', 'L', 'B', 'Q', 'Z', 'W', '\u00d6', 'L', 'F', 'P'],
            ['Z', 'W', 'E', 'I', 'N', 'S', 'I', 'E', 'B', 'E', 'N'],
            ['K', 'D', 'R', 'E', 'I', 'R', 'H', 'F', '\u00dc', 'N', 'F'],
            ['E', 'L', 'F', 'N', 'E', 'U', 'N', 'V', 'I', 'E', 'R'],
            ['W', 'A', 'C', 'H', 'T', 'Z', 'E', 'H', 'N', 'R', 'S'],
            ['B', 'S', 'E', 'C', 'H', 'S', 'F', 'M', 'U', 'H', 'R'],
            ['\ue4db', '\ue4db', '\ue4db', '\ue4db', ' ', ' ', ' ', ' ', ' ', ' ', ' '],
        ];

        // Systemzeit auslesen
        $act_time = new Datetime();

        $current_time_hour = (int) $act_time->format('H');     // Stunden in Variable schreiben
        $current_time_minute = (int) $act_time->format('i');   // Minuten in Variable schreiben

        // Die aktuelle Anzahl Minuten durch 10 Teilen, vom Ergebnis die Zahl vor dem Komma extrahieren (Funktion "floor"),
        // diese dann mit 10 malnehmen, das Ergebnis der ganzen Geschichte von der aktuellen Anzahl Minuten abziehen.
        // Somit erhält die Einer-Stelle der aktuellen Minuten. Falls die Einerstelle >= 5 (z.B. 14:37 Uhr), dann 5 abziehen.
        $number_of_dots = $current_time_minute - (10 * (floor($current_time_minute / 10)));
        if ($number_of_dots >= 5) {
            $number_of_dots = $number_of_dots - 5;
        }
        if ($current_time_minute == '00') {
            $fuenf = 0;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 0;
            $nach = 0;
            $vor = 0;
            $current_time_display = $current_time_hour;
        }
        if ($current_time_minute <= '05') {
            $fuenf = 0;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 0;
            $nach = 0;
            $vor = 0;
            $current_time_display = $current_time_hour;
        }
        if ($current_time_minute >= '05') {
            $fuenf = 1;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 0;
            $nach = 1;
            $vor = 0;
            $current_time_display = $current_time_hour;
        }
        if ($current_time_minute >= '10') {
            $fuenf = 0;
            $zehn = 1;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 0;
            $nach = 1;
            $vor = 0;
            $current_time_display = $current_time_hour;
        }
        if ($current_time_minute >= '15') {
            $fuenf = 0;
            $zehn = 0;
            $viertel = 1;
            $zwanzig = 0;
            $halb = 0;
            $nach = 1;
            $vor = 0;
            $current_time_display = $current_time_hour;
        }
        if ($current_time_minute >= '20') {
            $fuenf = 0;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 1;
            $halb = 0;
            $nach = 1;
            $vor = 0;
            $current_time_display = $current_time_hour;
        }
        if ($current_time_minute >= '25') {
            $fuenf = 1;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 1;
            $nach = 0;
            $vor = 1;
            $current_time_display = $current_time_hour + 1;
        }
        if ($current_time_minute == '30') {
            $fuenf = 0;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 1;
            $nach = 0;
            $vor = 0;
            $current_time_display = $current_time_hour + 1;
        }
        if ($current_time_minute > '30') {
            $fuenf = 0;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 1;
            $nach = 0;
            $vor = 0;
            $current_time_display = $current_time_hour + 1;
        }
        if ($current_time_minute >= '35') {
            $fuenf = 1;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 1;
            $nach = 1;
            $vor = 0;
            $current_time_display = $current_time_hour + 1;
        }
        if ($current_time_minute >= '40') {
            $fuenf = 0;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 1;
            $halb = 0;
            $nach = 0;
            $vor = 1;
            $current_time_display = $current_time_hour + 1;
        }
        if ($current_time_minute >= '45') {
            $fuenf = 0;
            $zehn = 0;
            $viertel = 1;
            $zwanzig = 0;
            $halb = 0;
            $nach = 0;
            $vor = 1;
            $current_time_display = $current_time_hour + 1;
        }
        if ($current_time_minute >= '50') {
            $fuenf = 0;
            $zehn = 1;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 0;
            $nach = 0;
            $vor = 1;
            $current_time_display = $current_time_hour + 1;
        }
        if ($current_time_minute >= '55') {
            $fuenf = 1;
            $zehn = 0;
            $viertel = 0;
            $zwanzig = 0;
            $halb = 0;
            $nach = 0;
            $vor = 1;
            $current_time_display = $current_time_hour + 1;
        }
        if ($current_time_display == '24') {
            $current_time_display = '00';
        }

        // IF Abfragen, um die 24H Darstellung auf die 12h Anzeige der Wörter anzupassen.
        // Beispiel: 1 Uhr ist das gleiche wie 13 Uhr.
        if (($current_time_display == '01') || ($current_time_display == '13')) {
            $hour = 1;
        }
        if (($current_time_display == '02') || ($current_time_display == '14')) {
            $hour = 2;
        }
        if (($current_time_display == '03') || ($current_time_display == '15')) {
            $hour = 3;
        }
        if (($current_time_display == '04') || ($current_time_display == '16')) {
            $hour = 4;
        }
        if (($current_time_display == '05') || ($current_time_display == '17')) {
            $hour = 5;
        }
        if (($current_time_display == '06') || ($current_time_display == '18')) {
            $hour = 6;
        }
        if (($current_time_display == '07') || ($current_time_display == '19')) {
            $hour = 7;
        }
        if (($current_time_display == '08') || ($current_time_display == '20')) {
            $hour = 8;
        }
        if (($current_time_display == '09') || ($current_time_display == '21')) {
            $hour = 9;
        }
        if (($current_time_display == '10') || ($current_time_display == '22')) {
            $hour = 10;
        }
        if (($current_time_display == '11') || ($current_time_display == '23')) {
            $hour = 11;
        }
        if (($current_time_display == '12') || ($current_time_display == '00')) {
            $hour = 12;
        }

        $spot = '#' . sprintf('%06X', $color);

        # ---- 1. ZEILE ----
        // Wenn $prefix == 1, das Wort "ES" einblenden
        if ($prefix == 1) {
            $qlocktwo[0][0] = "{$spot} E#";
            $qlocktwo[0][1] = "{$spot} S#";
        }
        // Wenn $prefix == 1, das Wort "IST" einblenden
        if ($prefix == 1) {
            $qlocktwo[0][3] = "{$spot} I#";
            $qlocktwo[0][4] = "{$spot} S#";
            $qlocktwo[0][5] = "{$spot} T#";
        }
        // Wenn $fuenf == 1, das Wort "FÜNF" einblenden
        if (($clock == 1) && ($fuenf == 1)) {
            $qlocktwo[0][7] = "{$spot} F#";
            $qlocktwo[0][8] = "{$spot} \u00dc#";
            $qlocktwo[0][9] = "{$spot} N#";
            $qlocktwo[0][10] = "{$spot} F#";
        }
        # ---- 2. ZEILE ----
        // Wenn $zehn == 1, das Wort "ZEHN" einblenden
        if (($clock == 1) && ($zehn == 1)) {
            $qlocktwo[1][0] = "{$spot} Z#";
            $qlocktwo[1][1] = "{$spot} E#";
            $qlocktwo[1][2] = "{$spot} H#";
            $qlocktwo[1][3] = "{$spot} N#";
        }
        if (($clock == 1) && ($zwanzig == 1)) {
            $qlocktwo[1][4] = "{$spot} Z#";
            $qlocktwo[1][5] = "{$spot} W#";
            $qlocktwo[1][6] = "{$spot} A#";
            $qlocktwo[1][7] = "{$spot} N#";
            $qlocktwo[1][8] = "{$spot} Z#";
            $qlocktwo[1][9] = "{$spot} I#";
            $qlocktwo[1][10] = "{$spot} G#";
        }
        # ---- 3. ZEILE ----
        // Wenn $viertel == 1, das Wort "VIERTEL" einblenden
        if (($clock == 1) && ($viertel == 1)) {
            $qlocktwo[2][4] = "{$spot} V#";
            $qlocktwo[2][5] = "{$spot} I#";
            $qlocktwo[2][6] = "{$spot} E#";
            $qlocktwo[2][7] = "{$spot} R#";
            $qlocktwo[2][8] = "{$spot} T#";
            $qlocktwo[2][9] = "{$spot} E#";
            $qlocktwo[2][10] = "{$spot} L#";
        }
        # ---- 4. ZEILE ----
        // Wenn $nach == 1, das Wort "NACH" einblenden
        if (($clock == 1) && ($nach == 1)) {
            $qlocktwo[3][2] = "{$spot} N#";
            $qlocktwo[3][3] = "{$spot} A#";
            $qlocktwo[3][4] = "{$spot} C#";
            $qlocktwo[3][5] = "{$spot} H#";
        }
        // Wenn $vor == 1, das Wort "VOR" einblenden
        if (($clock == 1) && ($vor == 1)) {
            $qlocktwo[3][6] = "{$spot} V#";
            $qlocktwo[3][7] = "{$spot} O#";
            $qlocktwo[3][8] = "{$spot} R#";
        }
        # ---- 5. ZEILE ----
        // Wenn $halb == 1, das Wort "HALB" einblenden
        if (($clock == 1) && ($halb == 1)) {
            $qlocktwo[4][0] = "{$spot} H#";
            $qlocktwo[4][1] = "{$spot} A#";
            $qlocktwo[4][2] = "{$spot} L#";
            $qlocktwo[4][3] = "{$spot} B#";
        }
        // Wenn $hour == 12 oder == 00, das Wort "ZWÖLF" einblenden
        if (($clock == 1) && ($hour == 12)) {
            $qlocktwo[4][5] = "{$spot} Z#";
            $qlocktwo[4][6] = "{$spot} W#";
            $qlocktwo[4][7] = "{$spot} \u00d6#";
            $qlocktwo[4][8] = "{$spot} L#";
            $qlocktwo[4][9] = "{$spot} F#";
        }
        # ---- 6. ZEILE ----
        // Wenn $hour == 2, das Z von ZWEI einblenden.
        if (($clock == 1) && ($hour == 2)) {
            $qlocktwo[5][0] = "{$spot} Z#";
            $qlocktwo[5][1] = "{$spot} W#";
        }
        // Wenn $hour == 2 oder == 1, das EI von zwEI oder EIn einblenden
        if (($clock == 1) && (($hour == 2) || ($hour == 1))) {
            $qlocktwo[5][2] = "{$spot} E#";
            $qlocktwo[5][3] = "{$spot} I#";
        }
        // Wenn $hour == 1 ($current_time_hour = 1 oder 13), das N "EIN" einblenden
        if (($clock == 1) && ($hour == 1)) {
            $qlocktwo[5][4] = "{$spot} N#";
        }
        // S von EINS nur einblenden, $current_time_display == '01' UND $current_time_minute größer/gleich '05'
        // ICH WILL IMMER DAS S :) if (($clock == 1) &&  (($current_time_minute >='05') && ($current_time_display == '01')) ) {
        if (($clock == 1) && (($hour == 1) && ($current_time_minute >= '05'))) {
            $qlocktwo[5][5] = "{$spot} S#";
        }
        // Wenn $hour == 7 ($current_time_hour = 7 oder 19), das Wort "SIEBEN" einblenden
        elseif (($clock == 1) && ($hour == 7)) {
            $qlocktwo[5][5] = "{$spot} S#";
            $qlocktwo[5][6] = "{$spot} I#";
            $qlocktwo[5][7] = "{$spot} E#";
            $qlocktwo[5][8] = "{$spot} B#";
            $qlocktwo[5][9] = "{$spot} E#";
            $qlocktwo[5][10] = "{$spot} N#";
        }
        # ---- 7. ZEILE ----
        // Wenn $hour == 3 ($current_time_hour = 3 oder 15), das Wort "DREI" einblenden
        if (($clock == 1) && ($hour == 3)) {
            $qlocktwo[6][1] = "{$spot} D#";
            $qlocktwo[6][2] = "{$spot} R#";
            $qlocktwo[6][3] = "{$spot} E#";
            $qlocktwo[6][4] = "{$spot} I#";
        }
        // Wenn $hour == 5 ($current_time_hour = 5 oder 17), das Wort "FÜNF" einblenden
        if (($clock == 1) && ($hour == 5)) {
            $qlocktwo[6][7] = "{$spot} F#";
            $qlocktwo[6][8] = "{$spot} \u00dc#";
            $qlocktwo[6][9] = "{$spot} N#";
            $qlocktwo[6][10] = "{$spot} F#";
        }
        # ---- 8. ZEILE ----
        // Wenn $hour == 11 ($current_time_hour = 11 oder 23), das Wort "ELF" einblenden
        if (($clock == 1) && ($hour == 11)) {
            $qlocktwo[7][0] = "{$spot} E#";
            $qlocktwo[7][1] = "{$spot} L#";
            $qlocktwo[7][2] = "{$spot} F#";
        }
        // Wenn $hour == 9 ($current_time_hour = 9 oder 21), das Wort "NEUN" einblenden
        if (($clock == 1) && ($hour == 9)) {
            $qlocktwo[7][3] = "{$spot} N#";
            $qlocktwo[7][4] = "{$spot} E#";
            $qlocktwo[7][5] = "{$spot} U#";
            $qlocktwo[7][6] = "{$spot} N#";
        }
        // Wenn $hour == 4 ($current_time_hour = 4 oder 16), das Wort "VIER" einblenden
        if (($clock == 1) && ($hour == 4)) {
            $qlocktwo[7][7] = "{$spot} V#";
            $qlocktwo[7][8] = "{$spot} I#";
            $qlocktwo[7][9] = "{$spot} E#";
            $qlocktwo[7][10] = "{$spot} R#";
        }
        # ---- 9. ZEILE ----
        // Wenn $hour == 8 ($current_time_hour = 8 oder 20), das Wort "ACHT" einblenden
        if (($clock == 1) && ($hour == 8)) {
            $qlocktwo[8][1] = "{$spot} A#";
            $qlocktwo[8][2] = "{$spot} C#";
            $qlocktwo[8][3] = "{$spot} H#";
            $qlocktwo[8][4] = "{$spot} T#";
        }
        // Wenn $hour == 10 ($current_time_hour = 10 oder 22), das Wort "ZEHN" einblenden
        if (($clock == 1) && ($hour == 10)) {
            $qlocktwo[8][5] = "{$spot} Z#";
            $qlocktwo[8][6] = "{$spot} E#";
            $qlocktwo[8][7] = "{$spot} H#";
            $qlocktwo[8][8] = "{$spot} N#";
        }
        # ---- 10. ZEILE ----
        // Wenn $hour == 6 ($current_time_hour = 6 oder 18), das Wort "SECHS" einblenden
        if (($clock == 1) && ($hour == 6)) {
            $qlocktwo[9][1] = "{$spot} S#";
            $qlocktwo[9][2] = "{$spot} E#";
            $qlocktwo[9][3] = "{$spot} C#";
            $qlocktwo[9][4] = "{$spot} H#";
            $qlocktwo[9][5] = "{$spot} S#";
        }
        // Das Wort "Uhr" einblenden, falls $suffix == 1
        if (($suffix == 1) || ($current_time_minute <= '04')) {
            $qlocktwo[9][8] = "{$spot} U#";
            $qlocktwo[9][9] = "{$spot} H#";
            $qlocktwo[9][10] = "{$spot} R#";
        }
        // Punkte für die 4 Minuten zwischen den Schritten anzeigen, pro Minute wird ein Punkt angezeigt.
        if (($clock == 1) && ($number_of_dots >= 1)) {
            $qlocktwo[10][0] = "{$spot} \ue4db#";
        }
        if (($clock == 1) && ($number_of_dots >= 2)) {
            $qlocktwo[10][1] = "{$spot} \ue4db#";
        }
        if (($clock == 1) && ($number_of_dots >= 3)) {
            $qlocktwo[10][2] = "{$spot} \ue4db#";
        }
        if (($clock == 1) && ($number_of_dots >= 4)) {
            $qlocktwo[10][3] = "{$spot} \ue4db#";
        }
        // Transponieren von Zeilen auf Spalten orientiertes Layout und Ausgeben
        for ($i = 0; $i < 11; $i++) {
            $column = array_column($qlocktwo, $i);
            $string = implode("\n", $column);
            //echo $string . PHP_EOL;
            $this->SetItemText($page, $row++, $string);
        }
    }

}
