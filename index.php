<?php

/************************************************************
 * search_replace_interface.php
 *
 * Mini-interface web pour effectuer un "search & replace"
 * avancé dans une base MySQL/MariaDB, sans passer par la CLI.
 *
 *  - Détecte automatiquement la clé primaire (ou index unique),
 *    sinon fallback sur *toutes* les colonnes pour le WHERE.
 *  - Repère les colonnes char/text/blob.
 *  - Dans ces colonnes, essaie à chaque fois (plusieurs passes) :
 *       * unserialize (PHP),
 *       * json_decode,
 *       * base64_decode,
 *       * fallback str_replace direct.
 *  - Génère un rapport des actions et erreurs.
 *
 * ATTENTION :
 *   1. Protégez ce fichier si vous le mettez sur un vrai serveur.
 *   2. Sauvegardez toujours votre base avant ce genre d'opération.
 ************************************************************/

// On va afficher un petit HTML minimaliste
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <title>Search & Replace Interface</title>
    <style>
        body {
            font-family: sans-serif;
            margin: 20px;
        }
        label { display: block; margin-top: 8px; }
        input[type="text"], input[type="password"] {
            width: 300px;
        }
        textarea {
            width: 90%;
            height: 300px;
            margin-top: 20px;
        }
        .submit-btn {
            margin-top: 20px;
            padding: 8px 16px;
            cursor: pointer;
        }
        .section {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 4px;
            background: #f9f9f9;
        }
        .errors {
            color: red;
            font-weight: bold;
        }
    </style>
</head>
<body>
<h1>Search & Replace dans la base de données</h1>

<?php

// Récupération du formulaire s'il est soumis
$host    = $_POST['host']    ?? 'localhost';
$dbname  = $_POST['dbname']  ?? '';
$user    = $_POST['user']    ?? '';
$pass    = $_POST['pass']    ?? '';
$search  = $_POST['search']  ?? '';
$replace = $_POST['replace'] ?? '';

// Petite fonction utilitaire pour échapper les valeurs affichées
function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Variable pour stocker et afficher les logs (on accumule au fur et à mesure)
$reportLogs = '';
$errors = [];

// Fonction pour ajouter un message au rapport
function addLog($msg) {
    global $reportLogs;
    $reportLogs .= $msg."\n";
}

// On ne lance le traitement que si le formulaire est soumis ET tous les champs remplis
if (isset($_POST['do_replace'])) {
    // Vérif champs obligatoires
    if ($host && $dbname && $user && $search !== '' && $replace !== '') {
        // On tente la connexion + traitement
        try {
            // Connexion
            $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";
            $pdo = new PDO($dsn, $user, $pass, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);

            addLog("Connexion établie avec la base `$dbname`.");

            // Lister les tables
            $tables = [];
            $stmtT = $pdo->query("SHOW TABLES");
            while ($row = $stmtT->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            addLog("Tables détectées : ".implode(', ', $tables));

            foreach ($tables as $table) {
                addLog("\n### Table `$table` ###");

                // Détecter PK ou unique
                $pkCols = detectPkOrUnique($pdo, $table);

                // Lister colonnes
                $allCols = [];
                $targetCols = [];
                $stmtC = $pdo->query("SHOW COLUMNS FROM `$table`");
                while ($c = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                    $allCols[] = $c['Field'];
                    if (preg_match('/(char|text|blob)/i', $c['Type'])) {
                        $targetCols[] = $c['Field'];
                    }
                }
                if (empty($targetCols)) {
                    addLog(" -> Aucune colonne texte/blob. On ignore.");
                    continue;
                }
                addLog("Colonnes ciblées : ".implode(', ', $targetCols));

                // Sélectionner les données
                $colsForSelect = [];
                if ($pkCols === "ALL") {
                    // toutes les colonnes
                    $colsForSelect = $allCols;
                } else {
                    // pk + target
                    $colsForSelect = array_unique(array_merge($pkCols, $targetCols));
                }
                $colListSql = "`".implode("`, `", $colsForSelect)."`";
                $sqlSelect = "SELECT $colListSql FROM `$table`";
                $stmtData = $pdo->query($sqlSelect);

                $rowCount = 0;
                $updateCount = 0;

                while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
                    $rowCount++;
                    $updateNeeded = false;
                    $updates = [];

                    // Pour chaque colonne texte, on tente decode+replace
                    foreach ($targetCols as $colName) {
                        $originalVal = $row[$colName];
                        if ($originalVal === null) continue;

                        $newVal = decodeReplaceEncode($originalVal, $search, $replace);

                        if ($newVal !== $originalVal) {
                            $updateNeeded = true;
                            $updates[$colName] = $newVal;
                        }
                    }

                    if ($updateNeeded && !empty($updates)) {
                        // Construire l'UPDATE
                        $setParts = [];
                        $params   = [];
                        foreach ($updates as $c => $val) {
                            $setParts[] = "`$c` = ?";
                            $params[] = $val;
                        }
                        // WHERE
                        $whereClause = buildWhereClause($pkCols, $row, $params, $allCols);

                        $sqlUp = "UPDATE `$table` SET ".implode(", ", $setParts)." WHERE $whereClause";

                        try {
                            $upStmt = $pdo->prepare($sqlUp);
                            $upStmt->execute($params);
                            $updateCount++;
                        } catch (Exception $uE) {
                            addLog("ERREUR lors de l'UPDATE : ".$uE->getMessage());
                            $errors[] = "UPDATE `$table` : ".$uE->getMessage();
                        }
                    }
                }
                addLog(" -> $rowCount lignes examinées, $updateCount mises à jour.");
            }

        } catch (Exception $e) {
            addLog("ERREUR : ".$e->getMessage());
            $errors[] = $e->getMessage();
        }
    } else {
        // Champs vides
        addLog("Veuillez remplir tous les champs obligatoires.");
    }
}

// --------------------------------------------
// Définition des fonctions importantes
// --------------------------------------------

/**
 * Détecte la (ou les) colonnes formant la clé primaire ou un index unique.
 * Sinon, renvoie la chaîne "ALL" pour dire qu'on devra se baser sur toutes les colonnes.
 */
function detectPkOrUnique(PDO $pdo, $table) {
    // 1) Clé primaire
    $sql = "SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'";
    $stmt = $pdo->query($sql);
    $pkCols = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pkCols[] = $row['Column_name'];
    }
    if (!empty($pkCols)) {
        return $pkCols;
    }

    // 2) Index unique
    $sql2 = "SHOW INDEX FROM `$table` WHERE Non_unique = 0 ORDER BY Seq_in_index";
    $stmt2 = $pdo->query($sql2);
    $uniqueCols = [];
    $foundIndexName = null;
    while ($row2 = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        if ($foundIndexName === null) {
            $foundIndexName = $row2['Key_name'];
        }
        if ($row2['Key_name'] !== $foundIndexName) {
            // s'il y a plusieurs indexes uniques, on se limite au premier
            break;
        }
        $uniqueCols[] = $row2['Column_name'];
    }
    if (!empty($uniqueCols)) {
        return $uniqueCols;
    }

    // 3) Rien trouvé => "ALL"
    return "ALL";
}

/**
 * Construit la clause WHERE en fonction des colonnes PK/unique ou ALL columns
 */
function buildWhereClause($pkCols, $rowData, &$params, $allCols) {
    if ($pkCols === "ALL") {
        $clauses = [];
        foreach ($allCols as $col) {
            $clauses[] = "`$col` <=> ?";
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

/**
 * Tente plusieurs passes de décodage/replace/encodage :
 *   - unserialize,
 *   - json_decode,
 *   - base64_decode,
 *   - fallback str_replace direct.
 * On fait jusqu'à 5 itérations pour gérer d'éventuelles imbrications.
 */
function decodeReplaceEncode($data, $search, $replace) {
    $maxPasses = 5;
    $old = $data;
    for ($i=0; $i<$maxPasses; $i++) {
        $new = tryDecodeOnePass($old, $search, $replace);
        if ($new === $old) {
            // plus de changement, on arrête
            break;
        }
        $old = $new;
    }
    return $old;
}

/**
 * Tente UN cycle de détection/décodage (unserialize, JSON, base64),
 * sinon fait un str_replace direct.
 */
function tryDecodeOnePass($data, $search, $replace) {
    // 1) unserialize
    $unser = @unserialize($data);
    if ($unser !== false || $data === serialize(false)) {
        $rep = recursiveReplace($unser, $search, $replace);
        return serialize($rep);
    }

    // 2) json
    $json = @json_decode($data, false);
    if (json_last_error() === JSON_ERROR_NONE && (is_object($json) || is_array($json))) {
        $rep = recursiveReplace($json, $search, $replace);
        return json_encode($rep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // 3) base64
    if (isBase64($data)) {
        $decoded = base64_decode($data, true);
        if ($decoded !== false && $decoded !== '') {
            $rep = str_replace($search, $replace, $decoded);
            return base64_encode($rep);
        }
    }

    // 4) fallback str_replace
    return str_replace($search, $replace, $data);
}

/**
 * Remplacement récursif dans les tableaux/objets/strings.
 */
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

/**
 * Vérifie si on est potentiellement en base64
 */
function isBase64($data) {
    if (!is_string($data)) return false;
    $len = strlen($data);
    if ($len === 0 || ($len % 4) !== 0) return false;
    return (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', $data);
}

// --------------------------------------------
// Affichage du formulaire + du résultat
// --------------------------------------------
?>

<div class="section">
    <form method="post">
        <label>Hôte (MySQL) :
            <input type="text" name="host" value="<?= e($host) ?>" placeholder="localhost">
        </label>
        <label>Nom de la base :
            <input type="text" name="dbname" value="<?= e($dbname) ?>">
        </label>
        <label>Utilisateur :
            <input type="text" name="user" value="<?= e($user) ?>">
        </label>
        <label>Mot de passe :
            <input type="password" name="pass" value="<?= e($pass) ?>">
        </label>
        <label>Valeur à rechercher :
            <input type="text" name="search" value="<?= e($search) ?>" >
        </label>
        <label>Valeur de remplacement :
            <input type="text" name="replace" value="<?= e($replace) ?>" >
        </label>

        <button class="submit-btn" type="submit" name="do_replace">
            Lancer le Search & Replace
        </button>
    </form>
</div>

<div class="section">
    <h2>Rapport d'exécution</h2>
    <textarea readonly><?= e($reportLogs) ?></textarea>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <?= e("Erreur(s) rencontrée(s) :\n - ".implode("\n - ", $errors)) ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
