#!/usr/bin/env php
<?php

/**
 * search_replace_ultimate.php
 *
 * Script pour effectuer un "search & replace" complet dans une base MySQL/MariaDB,
 * sans supposer l'existence d'une colonne `id`.
 * Il détecte automatiquement :
 *   - la clé primaire (ou un index unique, ou à défaut, toutes les colonnes pour le WHERE),
 *   - les colonnes de type texte/char/blob,
 *   - tente de décoder plusieurs fois :
 *       * PHP sérialisé,
 *       * JSON,
 *       * base64,
 *       * sinon fallback str_replace.
 *
 * Usage (en CLI) :
 *   php search_replace_ultimate.php \
 *       --host="localhost" \
 *       --db="nom_de_la_base" \
 *       --user="utilisateur" \
 *       --pass="mot_de_passe" \
 *       --search="ancienne_chaine" \
 *       --replace="nouvelle_chaine"
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

if (!$dbname || !$user || $search === '' || $replace === '') {
    exit(
        "Paramètres manquants ou incomplets.\n".
        "Exemple d'usage :\n".
        "php search_replace_ultimate.php --host=localhost --db=ma_base --user=root --pass=root --search=old --replace=new\n"
    );
}

// Pour collecter et afficher tous les éventuels messages d'erreur
$errors = [];

try {
    // Connexion à la base
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";
    $pdo = new PDO($dsn, $user, $pass, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);
} catch (Exception $e) {
    $msg = "ERREUR : Échec de la connexion à la base : " . $e->getMessage();
    echo $msg . "\n";
    $errors[] = $msg;
    exit(1);
}

// Récupération des tables
$tables = [];
try {
    $stmtTables = $pdo->query("SHOW TABLES");
    while ($row = $stmtTables->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
} catch (Exception $e) {
    $msg = "ERREUR : Impossible de lister les tables : " . $e->getMessage();
    echo $msg . "\n";
    $errors[] = $msg;
    exit(1);
}
echo "Tables détectées dans la base `$dbname`: " . implode(', ', $tables) . "\n\n";

//
// FONCTION DÉTECTANT LA CLEF PRIMAIRE OU INDEX UNIQUE
// Retourne un tableau des colonnes formant cette clef/unique.
// Si rien de tel n'est trouvé, retour "ALL", pour dire qu'on inclura toutes les colonnes.
//
function detectPkOrUnique(PDO $pdo, $table) {
    // 1) Tenter de détecter la clé primaire
    $sql = "SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'";
    $stmt = $pdo->query($sql);
    $pkCols = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pkCols[] = $row['Column_name'];
    }
    if (!empty($pkCols)) {
        return $pkCols;
    }

    // 2) À défaut, tenter de détecter un index unique (Non_unique=0)
    $sql2 = "SHOW INDEX FROM `$table` WHERE Non_unique = 0 ORDER BY Seq_in_index";
    $stmt2 = $pdo->query($sql2);
    $uniqueCols = [];
    $foundIndexName = null;
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        // S'il y a plusieurs index uniques, on prend le premier qu'on trouve
        if ($foundIndexName === null) {
            $foundIndexName = $row['Key_name'];
        }
        // Si on change d'index en cours de route, on arrête.
        if ($row['Key_name'] !== $foundIndexName) {
            break;
        }
        $uniqueCols[] = $row['Column_name'];
    }
    if (!empty($uniqueCols)) {
        return $uniqueCols;
    }

    // 3) Aucune PK ni Index unique -> on renvoie "ALL"
    return "ALL";
}

//
// FONCTION POUR CONSTRUIRE LA CLAUSE WHERE EN FONCTION DE LA PK/INDEX UNIQUE OU TOUTES COLONNES
//
function buildWhereClause(array $pkCols, array $rowData, array &$params, array $allCols) {
    // pkCols peut être un tableau des colonnes, ou la chaîne "ALL"
    // rowData : la ligne courante
    // params : on remplit ce tableau par référence
    // allCols : toutes les colonnes de la table

    if ($pkCols === "ALL") {
        // On met toutes les colonnes dans le WHERE
        $clauses = [];
        foreach ($allCols as $col) {
            // On ignore les valeurs null
            // Sinon on fait un `col IS NULL`
            // Mais ça devient trop complexe de distinguer, on peut faire `col<=>?`
            $clauses[] = "`$col` <=> ?"; // <=> permet de comparer NULL proprement
            $params[] = $rowData[$col];
        }
        return implode(" AND ", $clauses);
    } else {
        $clauses = [];
        foreach ($pkCols as $col) {
            $clauses[] = "`$col` <=> ?";
            $params[] = $rowData[$col];
        }
        return implode(" AND ", $clauses);
    }
}

//
// FONCTION POUR PARCOURIR RECURSIVEMENT UN OBJET / TABLEAU / STRING ET APPLIQUER str_replace
//
function recursiveReplace($value, $search, $replace) {
    if (is_array($value)) {
        $new = [];
        foreach ($value as $k => $v) {
            $nk = recursiveReplace($k, $search, $replace);
            $nv = recursiveReplace($v, $search, $replace);
            $new[$nk] = $nv;
        }
        return $new;
    } elseif (is_object($value)) {
        foreach ($value as $prop => $val) {
            $value->$prop = recursiveReplace($val, $search, $replace);
        }
        return $value;
    } elseif (is_string($value)) {
        return str_replace($search, $replace, $value);
    }
    return $value;
}

//
// FONCTION POUR SAVOIR SI UNE CHAINE POURRAIT ÊTRE base64
//
function isBase64($data) {
    if (!is_string($data)) return false;
    $len = strlen($data);
    // longueur multiple de 4, et caractères autorisés
    if ($len === 0 || ($len % 4) !== 0) return false;
    return (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', $data);
}

//
// FONCTION QUI TENTE DE DÉCODER + REMPLACER + RÉENCODER, PLUSIEURS FOIS (jusqu'à 5 passes)
//
function decodeReplaceEncode($data, $search, $replace) {
    // On fait un maximum de 5 passes
    // (si vous avez des imbrications plus profondes, augmentez ce chiffre)
    $maxPasses = 5;
    $oldData = $data;

    for ($i = 0; $i < $maxPasses; $i++) {
        $tryData = tryDecodeOnePass($oldData, $search, $replace);

        if ($tryData === $oldData) {
            // plus de changement, on arrête
            break;
        }
        // sinon, on réassigne et on boucle pour retenter un nouveau décode
        $oldData = $tryData;
    }

    return $oldData;
}

//
// FONCTION QUI TENTE UNE SEULE PASSE DE DÉCODAGE (unserialize, json, base64) PUIS str_replace
//
function tryDecodeOnePass($data, $search, $replace) {
    // 1. Tente unserialize
    $unserialized = @unserialize($data);
    // unserialize() retourne false si échec, ou si la chaîne représente "false"
    // On fait un check plus précis
    if ($unserialized !== false || $data === serialize(false)) {
        // on remplace récursivement
        $replaced = recursiveReplace($unserialized, $search, $replace);
        // on re-sérialise
        return serialize($replaced);
    }

    // 2. Tente JSON
    $jsonDecoded = @json_decode($data, false);
    if (json_last_error() === JSON_ERROR_NONE && (is_array($jsonDecoded) || is_object($jsonDecoded))) {
        $replaced = recursiveReplace($jsonDecoded, $search, $replace);
        return json_encode($replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // 3. Tente base64
    if (isBase64($data)) {
        $b64 = base64_decode($data, true);
        if ($b64 !== false && $b64 !== '') {
            // Remplacement direct sur la chaîne décodée
            $b64replaced = str_replace($search, $replace, $b64);
            return base64_encode($b64replaced);
        }
    }

    // 4. Fallback : simple str_replace
    return str_replace($search, $replace, $data);
}

// -------------------------------------------------------------

foreach ($tables as $table) {
    echo "### Table `$table` ###\n";

    // 1) Déterminer la clé primaire ou unique
    try {
        $pkCols = detectPkOrUnique($pdo, $table);
    } catch (Exception $e) {
        $msg = "ERREUR : Impossible de détecter la PK/Unique de `$table` : " . $e->getMessage();
        echo $msg . "\n";
        $errors[] = $msg;
        continue;
    }

    // 2) Lister toutes les colonnes et filtrer sur celles qui sont text/char/blob
    $allCols = [];        // on garde aussi la liste de toutes les colonnes pour le fallback "ALL"
    $targetCols = [];     // colonnes sur lesquelles on fera la recherche
    try {
        $stmtCols = $pdo->query("SHOW COLUMNS FROM `$table`");
        while ($c = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
            $allCols[] = $c['Field'];
            if (preg_match('/(char|text|blob)/i', $c['Type'])) {
                $targetCols[] = $c['Field'];
            }
        }
    } catch (Exception $e) {
        $msg = "ERREUR : Impossible de lister les colonnes de `$table` : " . $e->getMessage();
        echo $msg . "\n";
        $errors[] = $msg;
        continue;
    }

    if (empty($targetCols)) {
        echo " -> Aucune colonne text/char/blob à traiter.\n\n";
        continue;
    }

    echo "Colonnes ciblées : " . implode(', ', $targetCols) . "\n";

    // 3) Sélectionner les données
    //    On récupère PKCols OU toute la ligne si pkCols=ALL, plus les targetCols
    //    Mais pour l'update, on doit avoir toutes les colonnes du PK (ou ALL)
    $colsForSelect = [];
    if ($pkCols === "ALL") {
        // on sélectionne toutes les colonnes
        $colsForSelect = $allCols;
    } else {
        // on sélectionne pk + targetCols
        $colsForSelect = array_unique(array_merge($pkCols, $targetCols));
    }

    $colListSql = "`" . implode("`, `", $colsForSelect) . "`";
    $sqlSelect = "SELECT $colListSql FROM `$table`";

    try {
        $stmtData = $pdo->query($sqlSelect);
    } catch (Exception $e) {
        $msg = "ERREUR : Impossible de SELECT sur `$table` : " . $e->getMessage();
        echo $msg . "\n";
        $errors[] = $msg;
        continue;
    }

    $rowCount = 0;
    $updateCount = 0;

    while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
        $rowCount++;
        $updateNeeded = false;
        $updates = [];

        // On va mettre à jour chaque colonne ciblée si besoin
        foreach ($targetCols as $colName) {
            $originalVal = $row[$colName];
            if ($originalVal === null) {
                continue;
            }
            // Tenter le decode+replace+encode, sur plusieurs passes
            $newVal = decodeReplaceEncode($originalVal, $search, $replace);

            // S'il y a eu un changement
            if ($newVal !== $originalVal) {
                $updateNeeded = true;
                $updates[$colName] = $newVal;
            }
        }

        // Si au moins une colonne a changé
        if ($updateNeeded && !empty($updates)) {
            // Construire la requête UPDATE
            $setParts = [];
            $params = [];
            foreach ($updates as $c => $val) {
                $setParts[] = "`$c` = ?";
                $params[] = $val;
            }

            // WHERE condition
            $whereClause = buildWhereClause($pkCols, $row, $params, $allCols);

            $sqlUpdate = "UPDATE `$table` SET ".implode(", ", $setParts)." WHERE $whereClause";

            try {
                $stUpdate = $pdo->prepare($sqlUpdate);
                $stUpdate->execute($params);
                $updateCount++;
            } catch (Exception $e) {
                $msg = "ERREUR : UPDATE échoué (table `$table`) : " . $e->getMessage();
                echo $msg . "\n";
                $errors[] = $msg;
            }
        }
    }

    echo " -> $rowCount lignes examinées, $updateCount mises à jour.\n\n";
}

echo "Fin du traitement.\n";

// Récapitulatif d'erreurs
if (!empty($errors)) {
    echo "\n=== RÉCAPITULATIF DES ERREURS ===\n";
    foreach ($errors as $err) {
        echo " - $err\n";
    }
} else {
    echo "Aucune erreur signalée.\n";
}