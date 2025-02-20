<?php
header('Content-Type: text/html; charset=utf-8');

/*
 * Classe DB
 * Centralise la connexion unique et enregistre les requêtes exécutées.
 */
class DB
{
    private static ?mysqli $connection = null;
    public static array $queryLog = [];
    public static int $queryCount = 0;
    public static float $totalTime = 0.0;

    // Retourne la connexion unique (singleton)
    public static function getConnection(): mysqli
    {
        if (self::$connection === null) {
            self::$connection = new mysqli('localhost', 'root', '', 'cinema');
            if (self::$connection->connect_error) {
                die("Connection failed: " . self::$connection->connect_error);
            }
            self::$connection->set_charset("utf8");
        }
        return self::$connection;
    }

    // Exécute une requête SQL en enregistrant sa durée et en l'ajoutant au log
    public static function query(string $sql): mysqli_result|bool
    {
        $link = self::getConnection();
        $start = microtime(true);
        $result = mysqli_query($link, $sql);
        $end = microtime(true);
        $elapsed = $end - $start;
        self::$queryLog[] = $sql;
        self::$queryCount++;
        self::$totalTime += $elapsed;
        return $result;
    }
}

/*
 * Classe abstraite Table
 * Fournit des méthodes génériques pour manipuler une table.
 */
abstract class Table
{
    public static string $primaryKey;
    public static string $tableName;

    /*
     * Retourne un enregistrement sous forme de tableau associatif par son identifiant.
     */
    public static function getOne(int $id): ?array
    {
        $primaryKey = static::$primaryKey;
        $tableName = static::$tableName;
        $sql = "SELECT * FROM $tableName WHERE $primaryKey = " . (int) $id;
        $res = DB::query($sql);
        if (!$res) {
            die("Query failed: " . mysqli_error(DB::getConnection()));
        }
        $line = mysqli_fetch_assoc($res);
        return $line ? $line : null;
    }

    /*
     * Retourne tous les enregistrements de la table sous forme de tableaux associatifs.
     */
    public static function getAll(): array
    {
        $tableName = static::$tableName;
        $sql = "SELECT * FROM $tableName";
        $res = DB::query($sql);
        if (!$res) {
            die("Query failed: " . mysqli_error(DB::getConnection()));
        }
        $lines = [];
        while ($line = mysqli_fetch_assoc($res)) {
            $lines[] = $line;
        }
        return $lines;
    }
}

/*
 * Classe Film
 * Représente un film et permet, via l'ORM, de l'hydrater et d'associer son genre.
 */
class Film extends Table
{
    public static string $primaryKey = 'id_film';
    public static string $tableName = 'films';

    public ?int $id_film = null;
    public ?int $id_genre = null;
    public ?int $id_distributeur = null;
    public ?string $titre = null;
    public ?string $resum = null;
    public ?string $date_debut_affiche = null;
    public ?string $date_fin_affiche = null;
    public ?int $duree_minutes = null;
    public ?int $annee_production = null;

    // Pour le chargement lazy/eager du genre associé
    public ?Genre $genre = null;

    public function __construct()
    {
        // Constructeur vide
    }

    // Hydrate l'objet Film à partir d'un tableau associatif
    public function hydrateFromArray(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /*
     * Retourne tous les films sous forme d'objets Film.
     * Si $eager est true, précharge en une seule requête les genres associés.
     */
    public static function getAll(bool $eager = false): array
    {
        $filmDataList = parent::getAll();
        $films = [];
        foreach ($filmDataList as $data) {
            $film = new Film();
            $film->hydrateFromArray($data);
            $films[] = $film;
        }
        if ($eager) {
            // Récupère tous les id_genre uniques
            $genreIds = [];
            foreach ($films as $film) {
                if ($film->id_genre !== null) {
                    $genreIds[] = $film->id_genre;
                }
            }
            $genreIds = array_unique($genreIds);
            if (count($genreIds) > 0) {
                $ids = implode(',', $genreIds);
                $sql = "SELECT * FROM " . Genre::$tableName . " WHERE " . Genre::$primaryKey . " IN ($ids)";
                $res = DB::query($sql);
                if (!$res) {
                    die("Query failed: " . mysqli_error(DB::getConnection()));
                }
                $genreMap = [];
                while ($row = mysqli_fetch_assoc($res)) {
                    $genre = new Genre();
                    $genre->hydrateFromArray($row);
                    $genreMap[$genre->id_genre] = $genre;
                }
                // Associe à chaque film son objet Genre préchargé
                foreach ($films as $film) {
                    if (isset($genreMap[$film->id_genre])) {
                        $film->genre = $genreMap[$film->id_genre];
                    }
                }
            }
        }
        return $films;
    }

    /*
     * Chargement lazy : retourne l'objet Genre associé.
     * Si non chargé, effectue une requête pour l'hydrater.
     */
    public function getGenre(): ?Genre
    {
        if ($this->genre === null && $this->id_genre !== null) {
            $data = Genre::getOne($this->id_genre);
            if ($data) {
                $genre = new Genre();
                $genre->hydrateFromArray($data);
                $this->genre = $genre;
            }
        }
        return $this->genre;
    }
}

/*
 * Classe Genre
 * Représente un genre de film.
 */
class Genre extends Table
{
    public static string $primaryKey = 'id_genre';
    public static string $tableName = 'genres';

    public ?int $id_genre = null;
    public ?string $nom = null;

    public function __construct()
    {
        // Constructeur vide
    }

    // Hydrate l'objet Genre à partir d'un tableau associatif
    public function hydrateFromArray(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /*
     * Méthode d'enregistrement (insert/update) d'un genre.
     */
    public function save(): void
    {
        $link = DB::getConnection();
        $primaryKey = static::$primaryKey;
        $tableName = static::$tableName;
        $vars = get_object_vars($this);
        if (isset($this->$primaryKey) && !is_null($this->$primaryKey)) {
            // UPDATE
            $query = "UPDATE $tableName SET ";
            $fields = [];
            foreach ($vars as $key => $value) {
                if ($key !== $primaryKey) {
                    $escapedValue = mysqli_real_escape_string($link, (string) $value);
                    $fields[] = "$key = '$escapedValue'";
                }
            }
            $query .= implode(', ', $fields) . " WHERE $primaryKey = " . (int) $this->$primaryKey;
        } else {
            // INSERT
            $columns = [];
            $values = [];
            foreach ($vars as $key => $value) {
                if ($key !== $primaryKey) {
                    $columns[] = $key;
                    $values[] = "'" . mysqli_real_escape_string($link, (string) $value) . "'";
                }
            }
            $query = "INSERT INTO $tableName (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
        }
        if (!DB::query($query)) {
            die("Query failed: " . mysqli_error(DB::getConnection()));
        }
        echo $query . '<br>';
        if ((!isset($this->$primaryKey) || is_null($this->$primaryKey)) && mysqli_affected_rows(DB::getConnection()) > 0) {
            $this->$primaryKey = mysqli_insert_id(DB::getConnection());
        }
    }
}

/*
 * Classe Distributeur
 * Représente un distributeur de films.
 */
class Distributeur extends Table
{
    public static string $primaryKey = 'id_distributeur';
    public static string $tableName = 'distributeurs';

    public ?int $id_distributeur = null;
    public ?string $nom = null;

    public function __construct()
    {
        // Constructeur vide
    }
}

/*
 * ---------------------------
 * Code de l'application
 * ---------------------------
 */

// Inclusion du fichier CSS pour le style en grid (4 colonnes par row)
echo '<link rel="stylesheet" type="text/css" href="style.css">';

if (!isset($_GET['page'])) {
    echo '<h1>Liste des films du cinéma</h1><br>';
    // Chargement eager : les films sont préchargés avec leur genre associé
    $films = Film::getAll(true);
    echo '<div class="grid-container">';
    foreach ($films as $film) {
        echo '<div class="grid-item">';
        echo '<a href="?page=film&id_film=' . $film->id_film . '">' . $film->titre . '</a>';
        if ($film->genre !== null) {
            echo '<p>Genre : ' . $film->genre->nom . '</p>';
        }
        echo '</div>';
    }
    echo '</div>';
} elseif ($_GET['page'] == 'film') {
    $filmData = Film::getOne((int) $_GET['id_film']);
    if ($filmData) {
        $film = new Film();
        $film->hydrateFromArray($filmData);
        // Lazy loading du genre : chargé seulement à la demande
        $genre = $film->getGenre();
        echo '<h1>Détails du film "' . $film->titre . '"</h1><br>';
        echo '<pre>';
        var_dump($film);
        echo '</pre>';
        if ($genre !== null) {
            echo '<p>Genre : ' . $genre->nom . '</p>';
        }
    } else {
        echo "Film non trouvé.";
    }
} elseif ($_GET['page'] == 'genres') {
    echo '<h1>Liste des genres de films du cinéma</h1><br>';
    $genreDataList = Genre::getAll();
    echo '<div class="grid-container">';
    foreach ($genreDataList as $genreData) {
        echo '<div class="grid-item">';
        echo '<a href="?page=genre&id_genre=' . $genreData['id_genre'] . '">' . $genreData['nom'] . '</a>';
        echo '</div>';
    }
    echo '</div>';
} elseif ($_GET['page'] == 'genre') {
    $genreData = Genre::getOne((int) $_GET['id_genre']);
    if ($genreData) {
        $genre = new Genre();
        $genre->hydrateFromArray($genreData);
        echo '<h1>Détails du genre de film "' . $genre->nom . '"</h1><br>';
        echo '<pre>';
        var_dump($genre);
        echo '</pre>';
    } else {
        echo "Genre non trouvé.";
    }
} elseif ($_GET['page'] == 'add_genre_raw_code') {
    // Test d'ajout/mise à jour d'un genre
    $genre = new Genre();
    $genre->nom = 'heroic fantaisie';
    $genre->save();
    $genre->nom = 'heroic fantaisy';
    $genre->save();
} elseif ($_GET['page'] == 'hydrate_film') {
    // Test de l'hydratation d'un film (lazy loading du genre)
    $film = new Film();
    $film->id_film = 3571;
    $filmData = Film::getOne($film->id_film);
    if ($filmData) {
        $film->hydrateFromArray($filmData);
        echo '<h1>Détails du film "' . $film->titre . '" (lazy loading)</h1><br>';
        echo '<pre>';
        var_dump($film);
        echo '</pre>';
        $genre = $film->getGenre();
        if ($genre !== null) {
            echo '<p>Genre : ' . $genre->nom . '</p>';
        }
    } else {
        echo "Film non trouvé.";
    }
}

// Affichage des statistiques de requêtes en pied de page
echo "<footer>";
echo "Nombre de requêtes : " . DB::$queryCount . "<br>";
echo "Temps total des requêtes : " . round(DB::$totalTime, 5) . " secondes<br>";
echo "</footer>";
?>