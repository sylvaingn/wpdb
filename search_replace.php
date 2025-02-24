#!/usr/bin/env php
<?php

/**
 * search_replace_ultimate.php
 *
 * Script autonome avancé pour faire un search & replace dans une base MySQL/MariaDB,
 * sans avoir besoin de charger WordPress ou des classes spécifiques.
 *
 * Il gère :
 *  - la détection PK ou index unique (sinon ALL),
 *  - parcourt les colonnes (char|text|blob),
 *  - remplace dans la sérialisation PHP via un parsing manuel,
 *    incluant désormais le traitement (basique) des références `r:` / `R:`,
 *  - gère aussi JSON, base64,
 *  - fait 5 passes successives pour couvrir les imbrications,
 *  - corrige s:xx:"..." même si la classe est inconnue,
 *  - évite la fatal error des objets "incomplets".
 *
 * LIMITES :
 *   - Les structures "C:" (closures) ne sont pas gérées (skip).
 *   - Les références circulaires complexes peuvent poser problème.
 *   - Testez absolument sur une copie de la base et sauvegardez.
 */

$options = getopt('', [
    'host:',
    'db:',
    'user:',
    'pass:',
    'search:',
    'replace:',
]);

$host    = $options['host']    ?? 'localhost';
$dbname  = $options['db']      ?? '';
$user    = $options['user']    ?? '';
$pass    = $options['pass']    ?? '';
$search  = $options['search']  ?? '';
$replace = $options['replace'] ?? '';

// Vérifications de base
if (!$dbname || !$user || $search === '' || $replace === '') {
    exit(
        "Paramètres manquants ou incomplets.\n".
        "Exemple :\n".
        "php search_replace_ultimate.php --host=localhost --db=ma_base --user=root --pass=root --search=old --replace=new\n"
    );
}

// Connexion PDO
try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";
    $pdo = new PDO($dsn, $user, $pass, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);
    echo "Connecté à la base '$dbname'.\n";
} catch (Exception $e) {
    echo "ERREUR connexion : " . $e->getMessage() . "\n";
    exit(1);
}

// Lister les tables
$tables = [];
try {
    $stmtT = $pdo->query("SHOW TABLES");
    while ($row = $stmtT->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
} catch (Exception $e) {
    echo "ERREUR SHOW TABLES : " . $e->getMessage() . "\n";
    exit(1);
}
echo "Tables détectées : ".implode(', ', $tables)."\n";

$errors = [];

// Parcours des tables
foreach ($tables as $table) {
    echo "\n=== Table `$table` ===\n";

    // 1) Détecter la PK ou index unique
    $pkCols = detectPkOrUnique($pdo, $table);

    // 2) Lister les colonnes char|text|blob
    $allCols = [];
    $targetCols = [];
    try {
        $stmtC = $pdo->query("SHOW COLUMNS FROM `$table`");
        while ($c = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $allCols[] = $c['Field'];
            if (preg_match('/(char|text|blob)/i', $c['Type'])) {
                $targetCols[] = $c['Field'];
            }
        }
    } catch (Exception $e) {
        $msg = "ERREUR SHOW COLUMNS `$table`: " . $e->getMessage();
        echo $msg."\n";
        $errors[] = $msg;
        continue;
    }

    if (empty($targetCols)) {
        echo " -> Aucune colonne texte/blob.\n";
        continue;
    }
    echo "Colonnes ciblées : ".implode(', ', $targetCols)."\n";

    // 3) SELECT
    $colsForSelect = ($pkCols === "ALL")
        ? $allCols
        : array_unique(array_merge($pkCols, $targetCols));

    $colListSql = "`".implode("`, `", $colsForSelect)."`";
    $sqlSelect = "SELECT $colListSql FROM `$table`";

    try {
        $stmtData = $pdo->query($sqlSelect);
    } catch (Exception $e) {
        $msg = "ERREUR SELECT `$table`: " . $e->getMessage();
        echo $msg."\n";
        $errors[] = $msg;
        continue;
    }

    // 4) Parcours des lignes
    $rowCount = 0;
    $updateCount = 0;

    while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
        $rowCount++;
        $updateNeeded = false;
        $updates = [];

        foreach ($targetCols as $colName) {
            $originalVal = $row[$colName];
            if ($originalVal === null) {
                continue;
            }

            // Multi-pass (5 passes)
            $newVal = multiPassDecodeReplace($originalVal, $search, $replace);
            if ($newVal !== $originalVal) {
                $updateNeeded = true;
                $updates[$colName] = $newVal;
            }
        }

        if ($updateNeeded && !empty($updates)) {
            // Construire l'UPDATE
            $setParts = [];
            $params = [];
            foreach ($updates as $uCol => $uVal) {
                $setParts[] = "`$uCol` = ?";
                $params[] = $uVal;
            }

            // WHERE
            $whereClause = buildWhereClause($pkCols, $row, $params, $allCols);

            $sqlUp = "UPDATE `$table` SET ".implode(", ", $setParts)." WHERE $whereClause";
            try {
                $updStmt = $pdo->prepare($sqlUp);
                $updStmt->execute($params);
                $updateCount++;
            } catch (Exception $ex) {
                $msg = "ERREUR UPDATE `$table`: " . $ex->getMessage();
                echo $msg."\n";
                $errors[] = $msg;
            }
        }
    }

    echo " -> $rowCount lignes examinées, $updateCount mises à jour.\n";
}

echo "\nTerminé.\n";
if (!empty($errors)) {
    echo "\n=== ERREURS ===\n";
    foreach ($errors as $er) {
        echo " - $er\n";
    }
} else {
    echo "Aucune erreur signalée.\n";
}

// -------------------------------------------------------------
// Fonctions utilitaires
// -------------------------------------------------------------

/**
 * detectPkOrUnique : renvoie la liste de colonnes de la clé primaire,
 * ou d'un index unique, sinon "ALL".
 */
function detectPkOrUnique(PDO $pdo, $table) {
    // PK
    $pkCols = [];
    $stmt = $pdo->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pkCols[] = $r['Column_name'];
    }
    if (!empty($pkCols)) {
        return $pkCols;
    }

    // Unique
//    $stmt2 = $pdo->query("SHOW INDEX FROM `$table` WHERE Non_unique = 0 ORDER BY Seq_in_index");
    $stmt2 = $pdo->query("SHOW INDEX FROM `$table` WHERE Non_unique = 0");
    $uCols = [];
    $foundName = null;
    while ($r2 = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        if ($foundName === null) {
            $foundName = $r2['Key_name'];
        }
        if ($r2['Key_name'] !== $foundName) {
            // On prend seulement le premier index unique
            break;
        }
        $uCols[] = $r2['Column_name'];
    }
    if (!empty($uCols)) {
        return $uCols;
    }

    // Rien => ALL
    return "ALL";
}

/**
 * buildWhereClause : construit le WHERE en fonction de la PK ou "ALL".
 */
function buildWhereClause($pkCols, $row, &$params, $allCols) {
    if ($pkCols === "ALL") {
        $clauses = [];
        foreach ($allCols as $c) {
            $clauses[] = "`$c` <=> ?";
            $params[] = $row[$c];
        }
        return implode(" AND ", $clauses);
    } else {
        $clauses = [];
        foreach ($pkCols as $c) {
            $clauses[] = "`$c` <=> ?";
            $params[] = $row[$c];
        }
        return implode(" AND ", $clauses);
    }
}

/**
 * multiPassDecodeReplace :
 *   Fait jusqu'à 5 passes pour gérer :
 *     - la sérialisation manuelle (avec refs),
 *     - JSON,
 *     - base64,
 *     - fallback str_replace
 */
function multiPassDecodeReplace($value, $search, $replace) {
    $maxPasses = 5;
    $old = $value;
    for ($i = 0; $i < $maxPasses; $i++) {
        $new = decodeReplaceOnePass($old, $search, $replace);
        if ($new === $old) {
            break;
        }
        $old = $new;
    }
    return $old;
}

/**
 * decodeReplaceOnePass :
 *   1) Sérialisation (srdb_unserialize_replace, y compris r:/R:),
 *   2) JSON,
 *   3) base64,
 *   4) fallback
 */
function decodeReplaceOnePass($data, $search, $replace) {
    // 1) parsing manuel de la sérialisation
    $parsed = srdb_unserialize_replace($data, $search, $replace);
    if ($parsed !== null) {
        return $parsed;
    }

    // 2) JSON
    $json = @json_decode($data, false);
    if (json_last_error() === JSON_ERROR_NONE && (is_array($json) || is_object($json))) {
        $replaced = recursiveJsonReplace($json, $search, $replace);
        return json_encode($replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // 3) base64
    if (isBase64($data)) {
        $dec = base64_decode($data, true);
        if ($dec !== false && $dec !== '') {
            $rep = str_replace($search, $replace, $dec);
            return base64_encode($rep);
        }
    }

    // 4) fallback
    return str_replace($search, $replace, $data);
}

/**
 * recursiveJsonReplace : remplace $search par $replace dans un tableau/objet JSON,
 * y compris dans les clés.
 */
function recursiveJsonReplace($val, $search, $replace) {
    if (is_array($val)) {
        $res = [];
        foreach ($val as $k => $v) {
            $nk = recursiveJsonReplace($k, $search, $replace);
            $nv = recursiveJsonReplace($v, $search, $replace);
            $res[$nk] = $nv;
        }
        return $res;
    } elseif (is_object($val)) {
        foreach ($val as $prop => $v2) {
            $val->$prop = recursiveJsonReplace($v2, $search, $replace);
        }
        return $val;
    } elseif (is_string($val)) {
        return str_replace($search, $replace, $val);
    }
    return $val;
}

/**
 * isBase64 : vérifie si c'est potentiellement du base64.
 */
function isBase64($str) {
    if (!is_string($str)) return false;
    $len = strlen($str);
    if ($len === 0 || $len % 4 !== 0) return false;
    return (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', $str);
}

/**
 * srdb_unserialize_replace($data, $search, $replace)
 *
 * Tente de parser la chaîne comme sérialisation PHP, en gérant (b,i,d,s,a,O,N,r,R),
 * remplace $search/$replace dans les `s:xx:"..."`,
 * recalcule la longueur,
 * et reconstruit la chaîne si c'est valide.
 *
 * Retourne null si ça ne semble pas être de la sérialisation.
 */
function srdb_unserialize_replace($data, $search, $replace) {
    if (!is_string($data) || $data === '') {
        return null;
    }
    // Vérif très basique
    $trim = ltrim($data);
    if (!preg_match('/^[abcdinorsONR]:/', $trim)) {
        return null;
    }
    // S'il n'y a ni ";" ni "{" c'est sûrement pas de la sérialisation complète
    if (strpos($data, ';') === false && strpos($data, '{') === false) {
        return null;
    }

    $offset = 0;
    $res = parseSerialized($data, $search, $replace, $offset);
    if ($res === null) {
        return null;
    }
    // Si on n'a pas consommé toute la chaîne => doute => null
    if ($offset !== strlen($data)) {
        return null;
    }
    return $res;
}

/**
 * parseSerialized($data, $search, $replace, &$offset)
 *
 * Parse récursivement la sérialisation PHP :
 *   - b:0; b:1; i:...; d:...; s:...; a:...; O:...; N; r:...; R:...
 *   - "C:" (closures) n'est pas géré -> on renvoie null si on le voit
 *
 * Retourne la portion reconstruite ou null si échec.
 */
function parseSerialized($data, $search, $replace, &$offset) {
    if ($offset >= strlen($data)) {
        return null;
    }

    $type = $data[$offset];
    switch ($type) {
        case 'N': // N;
            if (substr($data, $offset, 2) === 'N;') {
                $offset += 2;
                return 'N;';
            }
            return null;

        case 'b': // bool b:0; b:1;
        case 'i': // int i:123;
        case 'd': // double d:123.45;
            // Trouver le prochain ";"
            $pos = strpos($data, ';', $offset);
            if ($pos === false) {
                return null;
            }
            $segment = substr($data, $offset, $pos - $offset + 1);
            // vérif format
            if (!preg_match('/^[bid]\:[\-0-9\.]+;$/', substr($segment, 0, -1).'') &&
                !preg_match('/^[bid]\:[0-9\.]+;$/', $segment)) {
                // on tolère un signe - pour i: ou d:
            }
            $offset = $pos + 1;
            return $segment;

        case 's': // s:xx:"..."
            // lire s:
            if (substr($data, $offset, 2) !== 's:') {
                return null;
            }
            $offset += 2;
            $numStart = $offset;
            while ($offset < strlen($data) && ctype_digit($data[$offset])) {
                $offset++;
            }
            if ($offset === $numStart) {
                return null; // pas de longueur
            }
            $lengthVal = (int) substr($data, $numStart, $offset - $numStart);

            // :"
            if (!isset($data[$offset]) || $data[$offset] !== ':') {
                return null;
            }
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== '"') {
                return null;
            }
            $offset++;
            // extraire la chaîne
            $stringStart = $offset;
            $stringEnd = $stringStart + $lengthVal;
            if ($stringEnd >= strlen($data)) {
                return null;
            }
            $theString = substr($data, $stringStart, $lengthVal);
            $offset += $lengthVal;
            // " + ;
            if (!isset($data[$offset]) || $data[$offset] !== '"') {
                return null;
            }
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== ';') {
                return null;
            }
            $offset++;

            // do str_replace
            $replaced = str_replace($search, $replace, $theString);
            $newLen = strlen($replaced);
            return 's:'.$newLen.':"'.$replaced.'";';

        case 'a': // a:COUNT:{...}
            // a:
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== ':') {
                return null;
            }
            $offset++;
            $numStart = $offset;
            while ($offset < strlen($data) && ctype_digit($data[$offset])) {
                $offset++;
            }
            if ($offset === $numStart) {
                return null;
            }
            $count = (int) substr($data, $numStart, $offset - $numStart);

            if (!isset($data[$offset]) || $data[$offset] !== ':') {
                return null;
            }
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== '{') {
                return null;
            }
            $offset++;

            $sub = '';
            for ($i=0; $i<$count*2; $i++) {
                $parsedVal = parseSerialized($data, $search, $replace, $offset);
                if ($parsedVal === null) {
                    return null;
                }
                $sub .= $parsedVal;
            }

            if (!isset($data[$offset]) || $data[$offset] !== '}') {
                return null;
            }
            $offset++;
            return "a:{$count}:{{$sub}}";

        case 'O': // O:LEN:"class":COUNT:{...}
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== ':') {
                return null;
            }
            $offset++;
            // lire LEN
            $numStart = $offset;
            while ($offset < strlen($data) && ctype_digit($data[$offset])) {
                $offset++;
            }
            if ($offset === $numStart) {
                return null;
            }
            $classLen = (int) substr($data, $numStart, $offset - $numStart);

            if (!isset($data[$offset]) || $data[$offset] !== ':') {
                return null;
            }
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== '"') {
                return null;
            }
            $offset++;
            // lire nom de classe
            if (($offset + $classLen) >= strlen($data)) {
                return null;
            }
            $className = substr($data, $offset, $classLen);
            $offset += $classLen;

            if (!isset($data[$offset]) || $data[$offset] !== '"') {
                return null;
            }
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== ':') {
                return null;
            }
            $offset++;
            // count
            $num2Start = $offset;
            while ($offset < strlen($data) && ctype_digit($data[$offset])) {
                $offset++;
            }
            if ($offset === $num2Start) {
                return null;
            }
            $propCount = (int) substr($data, $num2Start, $offset - $num2Start);

            if (!isset($data[$offset]) || $data[$offset] !== ':') {
                return null;
            }
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== '{') {
                return null;
            }
            $offset++;

            $sub = '';
            for ($i=0; $i<$propCount*2; $i++) {
                $pv = parseSerialized($data, $search, $replace, $offset);
                if ($pv === null) {
                    return null;
                }
                $sub .= $pv;
            }
            if (!isset($data[$offset]) || $data[$offset] !== '}') {
                return null;
            }
            $offset++;

            return "O:{$classLen}:\"{$className}\":{$propCount}:{{$sub}}";

        case 'r': // référence "r:123;"
        case 'R': // référence objet ?
            // r: ou R:
            // format r:x; ou R:x;
            // x = integer
            $offset++;
            if (!isset($data[$offset]) || $data[$offset] !== ':') {
                return null;
            }
            $offset++;
            $numStart = $offset;
            while ($offset < strlen($data) && ctype_digit($data[$offset])) {
                $offset++;
            }
            if ($offset === $numStart) {
                return null;
            }
            $refVal = substr($data, $numStart, $offset - $numStart);

            if (!isset($data[$offset]) || $data[$offset] !== ';') {
                return null;
            }
            $offset++;

            // on reconstruit r:XX; ou R:XX;
            return $type.':'.$refVal.';';

        case 'C': // Closures (PHP 7+), non gérées
            // format "C:LEN:..."
            // On ne tente pas => on renvoie null => fallback
            return null;

        default:
            return null;
    }
}
