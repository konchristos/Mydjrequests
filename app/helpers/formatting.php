<?php
//app/helpers/formatting.php
function mdjr_country_flag(?string $code): string
{
    if (!$code || strlen($code) !== 2) {
        return '';
    }

    $code = strtoupper($code);

    return mb_convert_encoding(
        '&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';',
        'UTF-8',
        'HTML-ENTITIES'
    );
}