<?php
/**
 * app/views/preprinted_geometry.php - where the boxes are on the pre-printed
 * tracking certificate blank.
 *
 * Measured off a flat scan of the blank (A4, 4.31 px/mm), in millimetres from
 * the top-left corner of the sheet. Shared by the entries-only layout
 * (tracking_sheet_preprinted.php) and the alignment test sheet
 * (tracking_align_test.php), so the test checks exactly what gets printed.
 *
 * A printer that feeds the paper a little off is corrected with
 * print_offset_x / print_offset_y in Settings - never by editing these.
 */
if (defined('PP_VALUE_LEFT')) {
    return;
}

// The table: value column from the divider to the right-hand border.
const PP_TABLE_LEFT  = 17.9;
const PP_VALUE_LEFT  = 81.9;
const PP_VALUE_RIGHT = 191.9;
// The eleven ruled lines of the table, top border first.
const PP_ROWS = [109.3, 120.9, 133.7, 146.2, 158.7, 171.2, 184.0, 196.5, 209.1, 221.6, 233.9];

// "This is to certify that on ____ / ________ / 20____": the three underlines.
const PP_DATE_LINE = 80.3;
const PP_DATE_DAY  = [74.0, 89.1];
const PP_DATE_MON  = [92.6, 123.7];
const PP_DATE_YR   = [133.0, 146.2];

// "Serial No." ends here; its baseline.
const PP_SERIAL_X  = 159.5;
const PP_SERIAL_Y  = 14.4;

// "Signed by Name: ______  Signature: ______"
const PP_SIGN_LINE = 251.3;
const PP_NAME      = [51.5, 131.8];
const PP_SIG       = [153.6, 191.9];

/** Millimetres for CSS: 81.9 -> "81.9mm". */
function pp_mm(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . 'mm';
}

/** The printer adjustment from Settings, as a CSS translate(). */
function pp_offset_css(): string
{
    return 'translate(' . pp_mm((float)setting('print_offset_x', '0')) . ', '
         . pp_mm((float)setting('print_offset_y', '0')) . ')';
}
