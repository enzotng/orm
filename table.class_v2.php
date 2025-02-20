<?php
header('Content-Type: text/html; charset=utf-8');
class DB
{
    private static ?mysqli $connection = null;
    public static array $queryLog = [];
    public static int $queryCount = 0;
    public static float $totalTime = 0.0;
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
abstract class Table
{
    public static string $primaryKey;
    public static string $tableName;
    public static function getAll(bool $eager = false, ?int $limit = null, ?int $offset = null): array
    {
        $tableName = static::$tableName;
        $sql = "SELECT * FROM $tableName";
        if ($limit !== null) {
            $sql .= " LIMIT " . (int) $limit;
            if ($offset !== null) {
                $sql .= " OFFSET " . (int) $offset;
            }
        }
        $res = DB::query($sql);
        if (!$res) {
            die("Query failed: " . mysqli_error(DB::getConnection()));
        }
        $objects = [];
        while ($line = mysqli_fetch_assoc($res)) {
            $obj = new static();
            $obj->hydrateFromArray($line);
            $objects[] = $obj;
        }
        return $objects;
    }
    public static function countAll(): int
    {
        $tableName = static::$tableName;
        $sql = "SELECT COUNT(*) as total FROM $tableName";
        $res = DB::query($sql);
        if (!$res) {
            die("Query failed: " . mysqli_error(DB::getConnection()));
        }
        $data = mysqli_fetch_assoc($res);
        return (int) $data['total'];
    }
    public static function getOne(int $id): ?static
    {
        $primaryKey = static::$primaryKey;
        $tableName = static::$tableName;
        $sql = "SELECT * FROM $tableName WHERE $primaryKey = " . (int) $id;
        $res = DB::query($sql);
        if (!$res) {
            die("Query failed: " . mysqli_error(DB::getConnection()));
        }
        $line = mysqli_fetch_assoc($res);
        if ($line) {
            $obj = new static();
            $obj->hydrateFromArray($line);
            return $obj;
        }
        return null;
    }
}
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
    public ?Genre $genre = null;
    public function __construct()
    {
    }
    public function hydrateFromArray(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
    public function __get($name)
    {
        if ($name === 'genre') {
            if ($this->genre === null && $this->id_genre !== null) {
                $this->genre = Genre::getOne($this->id_genre);
            }
            return $this->genre;
        }
        trigger_error("Undefined property: " . $name, E_USER_NOTICE);
        return null;
    }
    public static function getAll(bool $eager = true, ?int $limit = null, ?int $offset = null): array
    {
        $films = parent::getAll($eager, $limit, $offset);
        if ($eager) {
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
                foreach ($films as $film) {
                    if (isset($genreMap[$film->id_genre])) {
                        $film->genre = $genreMap[$film->id_genre];
                    }
                }
            }
        }
        return $films;
    }
    public static function getOne(int $id): ?static
    {
        $film = parent::getOne($id);
        if ($film !== null && $film->id_genre !== null) {
            $genre = Genre::getOne($film->id_genre);
            if ($genre !== null) {
                $film->genre = $genre;
            }
        }
        return $film;
    }
}
class Genre extends Table
{
    public static string $primaryKey = 'id_genre';
    public static string $tableName = 'genres';
    public ?int $id_genre = null;
    public ?string $nom = null;
    public function __construct()
    {
    }
    public function hydrateFromArray(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
    public function save(): void
    {
        $link = DB::getConnection();
        $primaryKey = static::$primaryKey;
        $tableName = static::$tableName;
        $vars = get_object_vars($this);
        if (isset($this->$primaryKey) && !is_null($this->$primaryKey)) {
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
        echo $query;
        if ((!isset($this->$primaryKey) || is_null($this->$primaryKey)) && mysqli_affected_rows(DB::getConnection()) > 0) {
            $this->$primaryKey = mysqli_insert_id(DB::getConnection());
        }
    }
}
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
function renderPagination(int $currentPage, int $totalPages): string
{
    $html = '<div class="pagination">';
    if ($currentPage > 1) {
        $html .= '<a href="?p=' . ($currentPage - 1) . '" class="prev">&laquo; Précédent</a> ';
    } else {
        $html .= '<span class="disabled prev">&laquo; Précédent</span> ';
    }
    $maxLinks = 5;
    $range = floor($maxLinks / 2);
    $start = $currentPage - $range;
    $end = $currentPage + $range;
    if ($start < 1) {
        $end += 1 - $start;
        $start = 1;
    }
    if ($end > $totalPages) {
        $start -= $end - $totalPages;
        $end = $totalPages;
        if ($start < 1) {
            $start = 1;
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $currentPage) {
            $html .= '<span class="current-page active">' . $i . '</span> ';
        } else {
            $html .= '<a href="?p=' . $i . '">' . $i . '</a> ';
        }
    }
    if ($currentPage < $totalPages) {
        $html .= '<a href="?p=' . ($currentPage + 1) . '" class="next">Suivant &raquo;</a>';
    } else {
        $html .= '<span class="disabled next">Suivant &raquo;</span>';
    }
    $html .= '</div>';
    return $html;
}
echo '<link rel="stylesheet" type="text/css" href="style.css">';
if (!isset($_GET['page'])) {
    echo '<h1>Balek\'Flix</h1>';
    $maxPerPage = 12;
    $currentPage = isset($_GET['p']) ? (int) $_GET['p'] : 1;
    if ($currentPage < 1) {
        $currentPage = 1;
    }
    $offset = ($currentPage - 1) * $maxPerPage;
    $totalFilms = Film::countAll();
    $totalPages = ceil($totalFilms / $maxPerPage);
    $films = Film::getAll(true, $maxPerPage, $offset);
    echo '<div class="grid-container">';
    foreach ($films as $film) {
        echo '<a class="grid-item" href="?page=film&id_film=' . $film->id_film . '">';
        echo '<h2>' . $film->titre . '</h2>';
        if ($film->genre !== null) {
            echo '<p><span>Genre : </span>' . $film->genre->nom . '</p>';
        }
        echo '</a>';
    }
    echo '</div>';
    echo renderPagination($currentPage, $totalPages);
} elseif ($_GET['page'] == 'film') {
    $film = Film::getOne((int) $_GET['id_film']);
    if ($film) {
        echo '<h1>Détails du film "' . $film->titre . '"</h1>';
        echo '<pre>';
        var_dump($film);
        echo '</pre>';
        if ($film->genre !== null) {
            echo '<p>Genre : ' . $film->genre->nom . '</p>';
        }
    } else {
        echo "Film non trouvé.";
    }
} elseif ($_GET['page'] == 'genres') {
    echo '<h1>Liste des genres de films du cinéma</h1>';
    $genreDataList = Genre::getAll(false);
    echo '<div class="grid-container">';
    foreach ($genreDataList as $genreData) {
        echo '<div class="grid-item">';
        echo '<a href="?page=genre&id_genre=' . $genreData->id_genre . '">' . $genreData->nom . '</a>';
        echo '</div>';
    }
    echo '</div>';
} elseif ($_GET['page'] == 'genre') {
    $genreData = Genre::getOne((int) $_GET['id_genre']);
    if ($genreData) {
        echo '<h1>Détails du genre de film "' . $genreData->nom . '"</h1>';
        echo '<pre>';
        var_dump($genreData);
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
    $film = Film::getOne(3571);
    if ($film) {
        echo '<h1>Détails du film "' . $film->titre . '" (eager loading)</h1>';
        echo '<pre>';
        var_dump($film);
        echo '</pre>';
        if ($film->genre !== null) {
            echo '<p>Genre : ' . $film->genre->nom . '</p>';
        }
    } else {
        echo "Film non trouvé.";
    }
}
echo "<footer>";
echo "Nombre de requêtes : " . DB::$queryCount . "<br>";
echo "Temps total des requêtes : " . round(DB::$totalTime, 5) . " secondes";
echo "</footer>";
?>