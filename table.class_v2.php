<?php
header('Content-Type: text/html; charset=utf-8');

/*
 * Classe DB
 * Centralise la connexion à la base de données
 */
class DB
{
    public static function getConnection(): mysqli
    {
        $link = new mysqli('localhost', 'root', '', 'cinema');
        if ($link->connect_error) {
            die("Connection failed: " . $link->connect_error);
        }
        $link->set_charset("utf8");
        return $link;
    }
}

/*
 * Classe abstraite Table
 * Contient les méthodes génériques pour manipuler une table de la base de données
 */
abstract class Table
{
    public static string $primaryKey;
    public static string $tableName;

    /*
     * Récupère un enregistrement par son identifiant
     */
    public static function getOne(int $id): ?array
    {
        $link = DB::getConnection();
        $primaryKey = static::$primaryKey;
        $tableName = static::$tableName;

        $query = "SELECT * FROM $tableName WHERE $primaryKey = $id";
        $res = mysqli_query($link, $query);
        if (!$res) {
            die("Query failed: " . mysqli_error($link));
        }
        $line = mysqli_fetch_assoc($res);
        mysqli_close($link);
        return $line ? $line : null;
    }

    /*
     * Récupère tous les enregistrements de la table
     */
    public static function getAll(): array
    {
        $link = DB::getConnection();
        $tableName = static::$tableName;
        $query = "SELECT * FROM $tableName";
        $res = mysqli_query($link, $query);
        if (!$res) {
            die("Query failed: " . mysqli_error($link));
        }
        $lines = [];
        while ($line = mysqli_fetch_assoc($res)) {
            $lines[] = $line;
        }
        mysqli_close($link);
        return $lines;
    }

    /*
     * Hydrate l'objet avec les données de la base de données
     * Pour éviter la création de propriétés dynamiques, seules les propriétés déclarées
     * explicitement dans la classe seront affectées.
     */
    public function hydrate(): void
    {
        $link = DB::getConnection();
        $primaryKey = static::$primaryKey;
        $tableName = static::$tableName;

        if (!isset($this->$primaryKey)) {
            mysqli_close($link);
            return;
        }

        $id = (int) $this->$primaryKey;
        $query = "SELECT * FROM $tableName WHERE $primaryKey = $id";
        $res = mysqli_query($link, $query);
        if (!$res) {
            die("Query failed: " . mysqli_error($link));
        }
        $data = mysqli_fetch_assoc($res);
        if ($data) {
            foreach ($data as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
        mysqli_close($link);
    }
}

/*
 * Classe Film
 * Hérite de Table et définit la table et la clé primaire associées.
 * Les propriétés sont déclarées explicitement pour éviter la création dynamique.
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

    public function __construct()
    {
    }
}

/*
 * Classe Genre
 * Hérite de Table et définit la table et la clé primaire associées.
 * Les propriétés sont déclarées explicitement pour éviter la création dynamique.
 */
class Genre extends Table
{
    public static string $primaryKey = 'id_genre';
    public static string $tableName = 'genres';

    public ?int $id_genre = null;
    public ?string $nom = null;

    public function __construct()
    {
    }

    /*
     * Enregistre (insert ou update) un genre dans la base de données
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

        if (!mysqli_query($link, $query)) {
            die("Query failed: " . mysqli_error($link));
        }

        echo $query . '<br>';

        if ((!isset($this->$primaryKey) || is_null($this->$primaryKey)) && mysqli_affected_rows($link) > 0) {
            $this->$primaryKey = mysqli_insert_id($link);
        }
        mysqli_close($link);
    }
}

/*
 * Classe Distributeur
 * Hérite de Table et définit la table et la clé primaire associées.
 * Les propriétés sont déclarées explicitement pour éviter la création dynamique.
 */
class Distributeur extends Table
{
    public static string $primaryKey = 'id_distributeur';
    public static string $tableName = 'distributeurs';

    public ?int $id_distributeur = null;
    public ?string $nom = null;

    public function __construct()
    {
    }
}

/*
 * Code de l'application
 * Affiche la liste des films, les détails d'un film, la liste des genres, etc.
 */
if (!isset($_GET['page'])) {
    echo '<h1>Liste des films du cinéma</h1><br>';
    $films = Film::getAll();
    foreach ($films as $filmData) {
        echo '<a href="?page=film&id_film=' . $filmData['id_film'] . '">' . $filmData['titre'] . '</a><br>';
    }
} elseif ($_GET['page'] == 'film') {
    $filmData = Film::getOne((int) $_GET['id_film']);
    if ($filmData) {
        $film = new Film();
        $film->id_film = (int) $filmData['id_film'];
        $film->hydrate();
        echo '<h1>Détails du film "' . $film->titre . '"</h1><br>';
        echo '<pre>';
        var_dump($film);
        echo '</pre>';
    } else {
        echo "Film non trouvé.";
    }
} elseif ($_GET['page'] == 'genres') {
    echo '<h1>Liste des genres de films du cinéma</h1><br>';
    $genres = Genre::getAll();
    foreach ($genres as $genreData) {
        echo '<a href="?page=genre&id_genre=' . $genreData['id_genre'] . '">' . $genreData['nom'] . '</a><br>';
    }
} elseif ($_GET['page'] == 'genre') {
    $genreData = Genre::getOne((int) $_GET['id_genre']);
    if ($genreData) {
        $genre = new Genre();
        $genre->id_genre = (int) $genreData['id_genre'];
        $genre->hydrate();
        echo '<h1>Détails du genre de film "' . $genre->nom . '"</h1><br>';
        echo '<pre>';
        var_dump($genre);
        echo '</pre>';
    } else {
        echo "Genre non trouvé.";
    }
} elseif ($_GET['page'] == 'add_genre_raw_code') {
    $genre = new Genre();
    $genre->nom = 'heroic fantaisie';
    $genre->save();

    $genre->nom = 'heroic fantaisy';
    $genre->save();
} elseif ($_GET['page'] == 'hydrate_film') {
    $film = new Film();
    $film->id_film = 3571;
    $film->hydrate();

    echo '<pre>';
    var_dump($film);
    echo '</pre>';
}
?>