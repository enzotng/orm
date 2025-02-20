<?php
header('Content-Type: text/html; charset=utf-8');
echo '<title>Balek\'Flix, Explore our knowledge</title>';

class DB {
    private static ?mysqli $connection = null;
    public static array $queryLog = [];
    public static int $queryCount = 0;
    public static float $totalTime = 0.0;
    
    public static function getConnection(): mysqli {
        if (self::$connection === null) {
            self::$connection = new mysqli('localhost', 'root', '', 'cinema');
            if (self::$connection->connect_error) {
                die("Connection failed: " . self::$connection->connect_error);
            }
            self::$connection->set_charset("utf8");
        }
        return self::$connection;
    }
    
    public static function query(string $sql): mysqli_result|bool {
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

abstract class Table {
    public static string $primaryKey;
    public static string $tableName;
    protected static array $cache = [];
    
    public static function getAll($sessionValue, ?int $limit = null, ?int $offset = null): array {
        session_start();

        if(isset($_SESSION[$sessionValue])){
            echo "yyyy
            ";
            return json_decode($_SESSION[$sessionValue]);
        }

        $tableName = static::$tableName;
        $sql = "SELECT * FROM $tableName";
        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset !== null) {
                $sql .= " OFFSET " . (int)$offset;
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

        $_SESSION[$sessionValue] = json_encode($objects);

        echo $_SESSION[$sessionValue];

        return $objects;
    }
    
    public static function countAll(): int {
        $tableName = static::$tableName;
        $sql = "SELECT COUNT(*) as total FROM $tableName";
        $res = DB::query($sql);
        if (!$res) {
            die("Query failed: " . mysqli_error(DB::getConnection()));
        }
        $data = mysqli_fetch_assoc($res);
        return (int)$data['total'];
    }
    
    public static function getOne(int $id): ?static {
        if (isset(static::$cache[$id])) {
            return static::$cache[$id];
        }
        $primaryKey = static::$primaryKey;
        $tableName = static::$tableName;
        $sql = "SELECT * FROM $tableName WHERE $primaryKey = " . (int)$id;
        $res = DB::query($sql);
        if (!$res) {
            die("Query failed: " . mysqli_error(DB::getConnection()));
        }
        $line = mysqli_fetch_assoc($res);
        if ($line) {
            $obj = new static();
            $obj->hydrateFromArray($line);
            static::$cache[$id] = $obj;
            return $obj;
        }
        return null;
    }
}

class Film extends Table {
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
    private ?Genre $genre = null;
        
    public function hydrateFromArray(array $data): void {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
    
    public function __get($name) {
        if ($name === 'genre') {
            if ($this->genre === null && $this->id_genre !== null) {
                $this->genre = Genre::getOne($this->id_genre);
            }
            return $this->genre;
        }
        trigger_error("Undefined property: " . $name, E_USER_NOTICE);
        return null;
    }
    
    public static function getAll($sessionValue, ?int $limit = null, ?int $offset = null): array {
        return parent::getAll($sessionValue, $limit, $offset);
    }
    
    public static function getOne(int $id): ?static {
        return parent::getOne($id);
    }
}

class Genre extends Table {
    public static string $primaryKey = 'id_genre';
    public static string $tableName = 'genres';
    
    public ?int $id_genre = null;
    public ?string $nom = null;
        
    public function hydrateFromArray(array $data): void {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
    
    public function save(): void {
        $link = DB::getConnection();
        $primaryKey = static::$primaryKey;
        $tableName = static::$tableName;
        $vars = get_object_vars($this);
        if (isset($this->$primaryKey) && !is_null($this->$primaryKey)) {
            $query = "UPDATE $tableName SET ";
            $fields = [];
            foreach ($vars as $key => $value) {
                if ($key !== $primaryKey) {
                    $escapedValue = mysqli_real_escape_string($link, (string)$value);
                    $fields[] = "$key = '$escapedValue'";
                }
            }
            $query .= implode(', ', $fields) . " WHERE $primaryKey = " . (int)$this->$primaryKey;
        } else {
            $columns = [];
            $values = [];
            foreach ($vars as $key => $value) {
                if ($key !== $primaryKey) {
                    $columns[] = $key;
                    $values[] = "'" . mysqli_real_escape_string($link, (string)$value) . "'";
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

class Distributeur extends Table {
    public static string $primaryKey = 'id_distributeur';
    public static string $tableName = 'distributeurs';
    
    public ?int $id_distributeur = null;
    public ?string $nom = null;
    }

function renderPagination(int $currentPage, int $totalPages): string {
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
    $currentPage = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    if ($currentPage < 1) { $currentPage = 1; }
    $offset = ($currentPage - 1) * $maxPerPage;
    $totalFilms = Film::countAll();
    $totalPages = ceil($totalFilms / $maxPerPage);
    $films = Film::getAll("AllFilms", $maxPerPage, $offset);
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
    $film = Film::getOne((int)$_GET['id_film']);
    if ($film) {
        echo '<h1>Détails du film "' . $film->titre . '"</h1>';
        echo '<pre>';
        var_dump($film);
        echo '</pre>';
        if ($film->genre !== null) {
            echo '<p>Genre : <a href="?page=genre&id_genre=' . $film->genre->id_genre . '">' . $film->genre->nom . '</a></p>';
        }
    } else {
        echo "Film non trouvé.";
    }
} elseif ($_GET['page'] == 'genres') {
    echo '<h1>Liste des genres de films du cinéma</h1>';
    $genreDataList = Genre::getAll("Genres");
    echo '<div class="grid-container">';
    foreach ($genreDataList as $genreData) {
        echo '<a class="grid-item" href="?page=genre&id_genre=' . $genreData->id_genre . '">';
        echo '<div>' . $genreData->nom . '</div>';
        echo '</a>';
    }
    echo '</div>';
} elseif ($_GET['page'] == 'genre') {
    $genreData = Genre::getOne((int)$_GET['id_genre']);
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
        echo '<h1>Détails du film "' . $film->titre . '" (lazy loading)</h1>';
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
echo "Temps total des requêtes : " . round(DB::$totalTime * 1000, 2) . " millisecondes";
echo "</footer>";
?>
