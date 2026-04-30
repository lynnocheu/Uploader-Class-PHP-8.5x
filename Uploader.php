<?php

declare(strict_types=1);

namespace Upload;

// ═══════════════════════════════════════════════════════════════════════════════
// Enums
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Formats d'image supportés.
 */
enum ImageFormat: string
{
    case Jpeg = 'jpg';
    case Png  = 'png';
    case Gif  = 'gif';
    case Webp = 'webp';
    case Bmp  = 'bmp';

    public function mimeType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png  => 'image/png',
            self::Gif  => 'image/gif',
            self::Webp => 'image/webp',
            self::Bmp  => 'image/bmp',
        };
    }

    public static function fromMime(string $mime): ?self
    {
        return match ($mime) {
            'image/jpeg', 'image/pjpeg', 'image/jpg' => self::Jpeg,
            'image/png',  'image/x-png'               => self::Png,
            'image/gif'                                => self::Gif,
            'image/webp', 'image/x-webp'              => self::Webp,
            'image/bmp',  'image/x-bmp',
            'image/x-ms-bmp', 'image/x-windows-bmp'  => self::Bmp,
            default                                    => null,
        };
    }

    public static function fromExtension(string $ext): ?self
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg', 'jpe' => self::Jpeg,
            'png'                => self::Png,
            'gif'                => self::Gif,
            'webp'               => self::Webp,
            'bmp'                => self::Bmp,
            default              => null,
        };
    }
}

// ───────────────────────────────────────────────────────────────────────────────

/**
 * Mode de redimensionnement.
 */
enum ResizeMode: string
{
    /** Contient l'image dans la boîte (pas de dépassement, ratio conservé). */
    case Fit     = 'fit';
    /** Couvre entièrement la boîte, recadre l'excédent (ratio conservé). */
    case Fill    = 'fill';
    /** Étire exactement aux dimensions cibles (ratio ignoré). */
    case Stretch = 'stretch';
    /** Contraint uniquement la largeur, hauteur calculée automatiquement. */
    case Width   = 'width';
    /** Contraint uniquement la hauteur, largeur calculée automatiquement. */
    case Height  = 'height';
    /** Cible un nombre de pixels total, ratio conservé. */
    case Pixels  = 'pixels';
}

// ───────────────────────────────────────────────────────────────────────────────

/**
 * Direction de flip.
 */
enum FlipDirection: string
{
    case Horizontal = 'h';
    case Vertical   = 'v';
    case Both       = 'both';
}

// ───────────────────────────────────────────────────────────────────────────────

/**
 * Ancrage du crop en mode Fill ou crop automatique.
 */
enum CropAnchor: string
{
    case Center      = 'C';
    case Top         = 'T';
    case Bottom      = 'B';
    case Left        = 'L';
    case Right       = 'R';
    case TopLeft     = 'TL';
    case TopRight    = 'TR';
    case BottomLeft  = 'BL';
    case BottomRight = 'BR';
}

// ═══════════════════════════════════════════════════════════════════════════════
// Result DTO (readonly — PHP 8.2+)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Résultat immuable retourné par Uploader::process().
 */
readonly class UploadResult
{
    public function __construct(
        /** Chemin complet du fichier de destination. */
        public string  $pathname,
        /** Nom du fichier (avec extension). */
        public string  $filename,
        /** Corps du nom (sans extension). */
        public string  $nameBody,
        /** Extension en minuscules, sans point. */
        public string  $extension,
        /** Type MIME détecté. */
        public string  $mimeType,
        /** Taille en octets du fichier produit. */
        public int     $size,
        /** Largeur en pixels (images uniquement). */
        public ?int    $width,
        /** Hauteur en pixels (images uniquement). */
        public ?int    $height,
        /** Message d'erreur, null si succès. */
        public ?string $error,
        /** true si le traitement s'est déroulé sans erreur. */
        public bool    $success,
    ) {}
}

// ═══════════════════════════════════════════════════════════════════════════════
// Uploader
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Classe d'upload moderne compatible PHP 8.4 / 8.5.
 *
 * Usage typique :
 *
 *   $result = (new Uploader())
 *       ->fromFiles($_FILES['avatar'])
 *       ->resize(800, 600, ResizeMode::Fit)
 *       ->convertTo(ImageFormat::Webp)
 *       ->webpQuality(80)
 *       ->process('/var/www/uploads/');
 *
 *   if ($result->success) {
 *       echo $result->pathname;
 *   }
 */
class Uploader
{
    // ── Source ────────────────────────────────────────────────────────────────

    private string $srcPathname = '';
    private string $srcName     = '';
    private string $srcNameBody = '';
    private string $srcNameExt  = '';
    private string $srcMime     = '';
    private int    $srcSize     = 0;
    private bool   $srcIsImage  = false;
    private ?int   $srcWidth    = null;
    private ?int   $srcHeight   = null;
    /** Fichier temporaire dont on est responsable (base64 / stream). */
    private bool   $srcIsTemp   = false;

    // ── Statut ────────────────────────────────────────────────────────────────

    private bool   $uploaded  = false;
    private bool   $processed = false;
    private string $error     = '';

    // ── Config fichier ────────────────────────────────────────────────────────

    private ?string $newNameBody    = null;
    private ?string $newNameExt     = null;
    private ?string $nameBodyPrefix = null;
    private ?string $nameBodySuffix = null;
    private bool    $safeName       = true;
    private bool    $forceExtension = true;
    private bool    $overwrite      = false;
    private bool    $autoRename     = true;
    private bool    $noScript       = true;
    private int     $dirChmod       = 0755;
    /** 'inline' | 'attachment' | null — null = pas de streaming navigateur. */
    private ?string $browserOutput  = null;
    /** Nom suggéré pour le téléchargement (Content-Disposition filename=). */
    private ?string $browserName    = null;

    // ── Config validation ─────────────────────────────────────────────────────

    private int   $maxSize   = 0;
    private array $allowed   = [];
    private array $forbidden = [];
    private bool  $checkMime = true;

    // ── Config resize ─────────────────────────────────────────────────────────

    private bool       $doResize      = false;
    private int        $targetWidth   = 150;
    private int        $targetHeight  = 150;
    private ResizeMode $resizeMode    = ResizeMode::Fit;
    private bool       $noEnlarging   = false;
    private bool       $noShrinking   = false;
    private CropAnchor $cropAnchor    = CropAnchor::Center;
    /** Crop manuel [x, y, w, h] en pixels appliqué après resize. */
    private ?array     $manualCrop    = null;
    /** Pre-crop [top, right, bottom, left] appliqué AVANT le resize. */
    private ?array     $manualPreCrop = null;

    // ── Config conversion ─────────────────────────────────────────────────────

    private ?ImageFormat $convertTo      = null;
    private int          $jpegQuality    = 85;
    /** Taille cible en octets pour ajustement automatique de la qualité JPEG. */
    private ?int         $jpegTargetSize = null;
    private int          $webpQuality    = 85;
    private ?int         $pngCompression = null;
    private bool         $interlace      = false;

    // ── Config transformations ────────────────────────────────────────────────

    private ?FlipDirection $flip       = null;
    private ?int           $rotate     = null;
    private bool           $autoRotate = true;

    // ── Config corrections ────────────────────────────────────────────────────

    private ?int $brightness = null;
    private ?int $contrast   = null;
    private ?int $opacity    = null;
    private bool $greyscale  = false;
    private bool $negative   = false;

    // ── Données de référence ──────────────────────────────────────────────────

    private readonly array $mimeMap;
    private readonly array $scriptBlacklist;

    private array $backgroundColor = [255, 255, 255]; // blanc par défaut

    // ─────────────────────────────────────────────────────────────────────────
    // Constructeur
    // ─────────────────────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->maxSize         = $this->parseSize((string) ini_get('upload_max_filesize'));
        $this->allowed         = self::defaultAllowedMimes();
        $this->mimeMap         = self::defaultMimeMap();
        $this->scriptBlacklist = [
            'php', 'php8', 'php7', 'php6', 'php5', 'php4', 'php3',
            'phtml', 'pht', 'phpt', 'phar', 'pl', 'py', 'cgi',
            'asp', 'aspx', 'js', 'sh', 'bash',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Sources d'upload
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Charge depuis un élément $_FILES (formulaire HTML ou AJAX multipart).
     *
     * @param array $fileEntry  Entrée $_FILES['field']
     */
    public function fromFiles(array $fileEntry): static
    {
        $this->reset();

        $code = (int) ($fileEntry['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($code !== UPLOAD_ERR_OK) {
            return $this->fail($this->phpUploadError($code));
        }

        $tmp = $fileEntry['tmp_name'] ?? '';

        if (!$tmp || !is_uploaded_file($tmp)) {
            return $this->fail('Fichier uploadé introuvable ou invalide.');
        }

        $this->srcPathname = $tmp;
        $this->srcSize     = (int) ($fileEntry['size'] ?? filesize($tmp));
        $this->uploaded    = true;

        $this->parseName($fileEntry['name'] ?? 'upload');
        $this->detectMime();
        $this->readImageDimensions();

        return $this;
    }

    /**
     * Charge depuis un flux XHR (raw body — Content-Type: application/octet-stream).
     *
     * @param string $filename  Nom de fichier indicatif (ex. depuis X-File-Name header).
     */
    public function fromStream(string $filename = 'upload'): static
    {
        $this->reset();

        $data = file_get_contents('php://input');

        if ($data === false || $data === '') {
            return $this->fail('Flux XHR vide ou illisible.');
        }

        return $this->storeTemp($data, $filename);
    }

    /**
     * Charge depuis une chaîne Base64.
     * Formats acceptés :
     *   - data URI  : "data:image/png;base64,iVBOR..."
     *   - préfixe   : "base64,iVBOR..."
     *   - raw       : "iVBORw0KGgo..."
     *
     * @param string $base64    Données encodées.
     * @param string $filename  Nom de fichier indicatif.
     */
    public function fromBase64(string $base64, string $filename = 'upload'): static
    {
        $this->reset();

        // Retire le préambule data URI ou "base64,"
        if (str_contains($base64, 'base64,')) {
            $base64 = substr($base64, strpos($base64, 'base64,') + 7);
        }

        $data = base64_decode(trim($base64), strict: true);

        if ($data === false) {
            return $this->fail('Décodage Base64 échoué — données invalides.');
        }

        return $this->storeTemp($data, $filename);
    }

    /**
     * Charge un fichier déjà présent sur le disque (traitement local).
     *
     * @param string $path  Chemin absolu ou relatif vers le fichier.
     */
    public function fromPath(string $path): static
    {
        $this->reset();

        if (!file_exists($path)) {
            return $this->fail("Le fichier « {$path} » n'existe pas.");
        }
        if (!is_readable($path)) {
            return $this->fail("Le fichier « {$path} » n'est pas lisible.");
        }

        $this->srcPathname = $path;
        $this->srcSize     = (int) filesize($path);
        $this->uploaded    = true;

        $this->parseName(basename($path));
        $this->detectMime();
        $this->readImageDimensions();

        return $this;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Configuration — fichier de sortie
    // ═════════════════════════════════════════════════════════════════════════

    /** Remplace le corps du nom et, optionnellement, l'extension. */
    public function setName(string $body, ?string $ext = null): static
    {
        $this->newNameBody = $body;
        if ($ext !== null) {
            $this->newNameExt = ltrim($ext, '.');
        }
        return $this;
    }

    /** Préfixe ajouté au corps du nom. */
    public function setPrefix(string $prefix): static
    {
        $this->nameBodyPrefix = $prefix;
        return $this;
    }

    /** Suffixe ajouté au corps du nom. */
    public function setSuffix(string $suffix): static
    {
        $this->nameBodySuffix = $suffix;
        return $this;
    }

    /** Remplace uniquement l'extension (sans point). */
    public function setExtension(string $ext): static
    {
        $this->newNameExt = ltrim($ext, '.');
        return $this;
    }

    /** Autorise l'écrasement d'un fichier existant. */
    public function overwrite(bool $allow = true): static
    {
        $this->overwrite = $allow;
        return $this;
    }

    /** Renommage automatique en cas de collision (foo.jpg → foo_1.jpg). */
    public function autoRename(bool $auto = true): static
    {
        $this->autoRename = $auto;
        return $this;
    }

    /** Nettoie automatiquement le nom de fichier (suppression de caractères dangereux). */
    public function safeName(bool $safe = true): static
    {
        $this->safeName = $safe;
        return $this;
    }

    /** Bloque les fichiers exécutables (scripts PHP, Python, bash…). */
    public function noScript(bool $block = true): static
    {
        $this->noScript = $block;
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sortie navigateur (chaînable avant process / serve)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Après process(), streame le fichier produit directement dans le navigateur
     * (Content-Disposition: inline). Idéal pour afficher une image ou un PDF
     * sans redirection.
     *
     * Usage :
     *   $result = (new Uploader())
     *       ->fromFiles($_FILES['photo'])
     *       ->resize(800, 600)
     *       ->display()          // ← active le streaming inline
     *       ->process('/var/uploads/');
     *
     * @param string|null $name  Nom de fichier suggéré (défaut = nom calculé).
     */
    public function display(?string $name = null): static
    {
        $this->browserOutput = 'inline';
        $this->browserName   = $name;
        return $this;
    }

    /**
     * Après process(), force le téléchargement du fichier produit
     * (Content-Disposition: attachment).
     *
     * Usage :
     *   $result = (new Uploader())
     *       ->fromPath('/tmp/report.pdf')
     *       ->download('rapport-final.pdf')
     *       ->process('/var/exports/');
     *
     * @param string|null $name  Nom suggéré à l'utilisateur (défaut = nom calculé).
     */
    public function download(?string $name = null): static
    {
        $this->browserOutput = 'attachment';
        $this->browserName   = $name;
        return $this;
    }

    /**
     * Traite le fichier, le streame immédiatement vers le navigateur
     * et — si $destDir est fourni — l'enregistre également sur disque.
     *
     * Sans $destDir le fichier n'est jamais écrit sur le disque (traitement
     * en mémoire via un buffer PHP, aucun fichier temporaire supplémentaire).
     *
     * Usage inline (affichage) :
     *   (new Uploader())
     *       ->fromFiles($_FILES['avatar'])
     *       ->resize(200, 200, ResizeMode::Fill)
     *       ->convertTo(ImageFormat::Webp)
     *       ->serve();                       // affiche dans le navigateur
     *
     * Usage inline + sauvegarde simultanée :
     *   (new Uploader())
     *       ->fromFiles($_FILES['avatar'])
     *       ->resize(200, 200, ResizeMode::Fill)
     *       ->serve('/var/uploads/', download: false);
     *
     * Usage téléchargement :
     *   (new Uploader())
     *       ->fromPath('/var/exports/report.pdf')
     *       ->serve(download: true, browserName: 'rapport-2024.pdf');
     *
     * @param string|null $destDir      Dossier de sauvegarde (null = ne pas sauvegarder).
     * @param bool        $download     false = inline, true = force-download.
     * @param string|null $browserName  Nom suggéré au navigateur.
     * @return UploadResult
     */
    public function serve(
        ?string $destDir    = null,
        bool    $download   = false,
        ?string $browserName = null,
    ): UploadResult {
        $this->browserOutput = $download ? 'attachment' : 'inline';
        $this->browserName   = $browserName;

        if ($destDir !== null) {
            // Sauvegarde sur disque ET streaming
            return $this->process($destDir);
        }

        // Streaming pur : on traite vers un fichier temporaire, on streame, on supprime
        return $this->processAndStream();
    }


    public function backgroundColor(int $r, int $g, int $b): static {
        $this->backgroundColor = [
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b)),
        ];
        return $this;
    }



    private function flattenToBackground(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);

        $dst = imagecreatetruecolor($w, $h);

        [$r, $g, $b] = $this->backgroundColor;
        $bg = imagecolorallocate($dst, $r, $g, $b);

        imagefilledrectangle($dst, 0, 0, $w, $h, $bg);

        // Fusion avec alpha
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

        return $dst;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Configuration — validation & sécurité
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Taille maximale autorisée.
     *
     * @param int|string $size  Entier (octets) ou notation courte : '5M', '500K', '2G'.
     */
    public function setMaxSize(int|string $size): static
    {
        $this->maxSize = $this->parseSize((string) $size);
        return $this;
    }

    /**
     * Ajoute des types MIME autorisés.
     * Wildcards supportés : 'image/*', '* /*'.
     */
    public function allow(string|array $mimes): static
    {
        $this->allowed = array_unique([...$this->allowed, ...(array) $mimes]);
        return $this;
    }

    /**
     * Ajoute des types MIME interdits (prioritaires sur la liste autorisée).
     */
    public function deny(string|array $mimes): static
    {
        $this->forbidden = array_unique([...$this->forbidden, ...(array) $mimes]);
        return $this;
    }

    /**
     * Remplace complètement la liste des types autorisés.
     */
    public function allowOnly(string|array $mimes): static
    {
        $this->allowed = (array) $mimes;
        return $this;
    }

    /** Active ou désactive la vérification MIME. */
    public function checkMime(bool $check = true): static
    {
        $this->checkMime = $check;
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. Configuration — resize
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Active le redimensionnement.
     *
     * @param int        $width   Largeur cible en pixels.
     * @param int        $height  Hauteur cible en pixels.
     * @param ResizeMode $mode    Stratégie appliquée.
     */
    public function resize(
        int $width,
        int $height,
        ResizeMode $mode = ResizeMode::Fit,
    ): static {
        $this->doResize    = true;
        $this->targetWidth  = max(1, $width);
        $this->targetHeight = max(1, $height);
        $this->resizeMode   = $mode;
        return $this;
    }

    /** Annule le resize si l'image source est plus petite que la cible. */
    public function noEnlarging(bool $v = true): static
    {
        $this->noEnlarging = $v;
        return $this;
    }

    /** Annule le resize si l'image source est plus grande que la cible. */
    public function noShrinking(bool $v = true): static
    {
        $this->noShrinking = $v;
        return $this;
    }

    /**
     * Ancrage utilisé lors du recadrage automatique (mode Fill).
     */
    public function cropAnchor(CropAnchor $anchor): static
    {
        $this->cropAnchor = $anchor;
        return $this;
    }

    /**
     * Crop manuel appliqué APRÈS le resize.
     *
     * @param int $x       Distance depuis le bord gauche (px).
     * @param int $y       Distance depuis le bord supérieur (px).
     * @param int $width   Largeur de la zone (px).
     * @param int $height  Hauteur de la zone (px).
     */
    public function crop(int $x, int $y, int $width, int $height): static
    {
        $this->manualCrop = [$x, $y, $width, $height];
        return $this;
    }

    /**
     * Rognage des bords appliqué AVANT le resize.
     * Formats acceptés pour chaque valeur : 10, '10', '10px', '10%'.
     * Ordre CSS : [top, right, bottom, left] ou [vertical, horizontal] ou [all].
     *
     * @param int|string|array $offsets
     */
    public function preCrop(int|string|array $offsets): static
    {
        $this->manualPreCrop = (array) $offsets;
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Configuration — conversion & qualité
    // ═════════════════════════════════════════════════════════════════════════

    /** Convertit l'image vers le format spécifié. */
    public function convertTo(ImageFormat $format): static
    {
        $this->convertTo = $format;
        return $this;
    }

    /** Qualité JPEG de 1 à 100. */
    public function jpegQuality(int $quality): static
    {
        $this->jpegQuality = max(1, min(100, $quality));
        return $this;
    }

    /**
     * Ajuste automatiquement la qualité JPEG pour atteindre une taille de fichier cible.
     * Prioritaire sur jpegQuality().
     *
     * @param int|string $size  Octets ou notation courte ('200K', '1M'…).
     */
    public function jpegMaxSize(int|string $size): static
    {
        $this->jpegTargetSize = $this->parseSize((string) $size);
        return $this;
    }

    /** Qualité WebP de 1 à 100. */
    public function webpQuality(int $quality): static
    {
        $this->webpQuality = max(1, min(100, $quality));
        return $this;
    }

    /** Niveau de compression PNG de 1 (rapide) à 9 (lent/petit). null = défaut zlib. */
    public function pngCompression(?int $level): static
    {
        $this->pngCompression = ($level === null) ? null : max(1, min(9, $level));
        return $this;
    }

    /** Active l'entrelacement (JPEG progressif / GIF animé). */
    public function interlace(bool $v = true): static
    {
        $this->interlace = $v;
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. Configuration — transformations géométriques
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Rotation en degrés (arrondie au multiple de 90 le plus proche).
     * Sens horaire : 90, 180, 270.
     */
    public function rotate(int $degrees): static
    {
        $this->rotate = (int) round($degrees / 90) * 90;
        return $this;
    }

    /**
     * Active / désactive la rotation automatique via les données EXIF
     * (photos prises avec un smartphone).
     */
    public function autoRotate(bool $v = true): static
    {
        $this->autoRotate = $v;
        return $this;
    }

    /** Flip horizontal, vertical, ou les deux. null pour désactiver. */
    public function flip(?FlipDirection $direction): static
    {
        $this->flip = $direction;
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. Configuration — corrections d'image
    // ═════════════════════════════════════════════════════════════════════════

    /** Luminosité : -127 (sombre) à +127 (clair). */
    public function brightness(int $value): static
    {
        $this->brightness = max(-127, min(127, $value));
        return $this;
    }

    /** Contraste : -127 (aplati) à +127 (accentué). */
    public function contrast(int $value): static
    {
        $this->contrast = max(-127, min(127, $value));
        return $this;
    }

    /** Opacité globale : 0 (transparent) à 100 (opaque). */
    public function opacity(int $value): static
    {
        $this->opacity = max(0, min(100, $value));
        return $this;
    }

    /** Convertit l'image en niveaux de gris. */
    public function greyscale(bool $v = true): static
    {
        $this->greyscale = $v;
        return $this;
    }

    /** Inverse les couleurs. */
    public function negative(bool $v = true): static
    {
        $this->negative = $v;
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 8. Traitement
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Traite le fichier source et le copie vers $destDir.
     *
     * Peut être appelé plusieurs fois sur le même fichier source pour générer
     * plusieurs variantes (thumbnail, grande résolution, WebP…).
     * Les paramètres sont remis à zéro après chaque appel.
     *
     * @param string $destDir  Répertoire de destination (créé si absent).
     * @return UploadResult    Objet readonly décrivant le résultat.
     */
    public function process(string $destDir): UploadResult
    {
        if (!$this->uploaded) {
            return $this->failResult($this->error ?: 'Aucun fichier chargé.');
        }

        $this->processed = false;
        $this->error     = '';

        // Normalise et crée le dossier cible
        $destDir = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR;

        if (!$this->ensureDir($destDir)) {
            return $this->failResult("Impossible de créer le répertoire « {$destDir} ».");
        }
        if (!is_writable($destDir)) {
            return $this->failResult("Le répertoire « {$destDir} » n'est pas accessible en écriture.");
        }

        // Validation taille
        if ($this->maxSize > 0 && $this->srcSize > $this->maxSize) {
            return $this->failResult(sprintf(
                'Fichier trop volumineux : %s (max autorisé : %s).',
                $this->formatSize($this->srcSize),
                $this->formatSize($this->maxSize),
            ));
        }

        // Validation MIME
        if ($this->checkMime) {
            $mimeError = $this->validateMime();
            if ($mimeError !== null) {
                return $this->failResult($mimeError);
            }
        }

        // Calcule le nom de destination et résout les collisions
        [$destFilename, $destExt] = $this->buildDestFilename();
        $destPathname = $this->resolveCollision($destDir, $destFilename, $destExt);

        if ($this->error !== '') {
            return $this->failResult($this->error);
        }

        // Traitement image ou simple copie
        $needsImageProcessing = $this->srcIsImage && (
            $this->doResize
            || $this->convertTo !== null
            || $this->manualCrop !== null
            || $this->manualPreCrop !== null
            || $this->flip !== null
            || $this->rotate !== null
            || ($this->autoRotate && $this->srcMime === 'image/jpeg')
            || $this->brightness !== null
            || $this->contrast !== null
            || $this->opacity !== null
            || $this->greyscale
            || $this->negative
        );

        if ($needsImageProcessing) {
            if (!$this->processImage($destPathname)) {
                return $this->failResult($this->error);
            }
        } else {
            if (!copy($this->srcPathname, $destPathname)) {
                return $this->failResult("Échec de la copie vers « {$destPathname} ».");
            }
        }

        $this->processed = true;
        $result = $this->buildResult($destPathname);

        // Streaming navigateur (si display() ou download() ont été appelés)
        if ($this->browserOutput !== null) {
            $this->streamToClient($destPathname, $result->mimeType, $result->filename);
        }

        // Remet les options de traitement à zéro (mais garde la source chargée)
        $this->resetProcessingOptions();

        return $result;
    }

    /**
     * Supprime le fichier temporaire créé par fromBase64() ou fromStream().
     * À appeler une fois tous les process() terminés.
     */
    public function clean(): void
    {
        if ($this->srcIsTemp && $this->srcPathname !== '' && file_exists($this->srcPathname)) {
            @unlink($this->srcPathname);
        }
        $this->srcIsTemp   = false;
        $this->srcPathname = '';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Accesseurs de statut & métadonnées source
    // ═════════════════════════════════════════════════════════════════════════

    public function isUploaded(): bool  { return $this->uploaded;   }
    public function isProcessed(): bool { return $this->processed;  }
    public function getError(): string  { return $this->error;      }

    /** Nom du fichier source (avec extension). */
    public function getSrcName(): string   { return $this->srcName;    }
    /** Type MIME détecté du fichier source. */
    public function getSrcMime(): string   { return $this->srcMime;    }
    /** Taille en octets du fichier source. */
    public function getSrcSize(): int      { return $this->srcSize;    }
    /** Largeur en pixels (null si non-image). */
    public function getSrcWidth(): ?int    { return $this->srcWidth;   }
    /** Hauteur en pixels (null si non-image). */
    public function getSrcHeight(): ?int   { return $this->srcHeight;  }
    /** true si le fichier source est une image reconnue par GD. */
    public function isImage(): bool        { return $this->srcIsImage; }

    // ─────────────────────────────────────────────────────────────────────────
    // Streaming navigateur (privé)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Traite vers un fichier temporaire, streame, puis supprime le temporaire.
     * Utilisé par serve() sans $destDir.
     */
    private function processAndStream(): UploadResult
    {
        if (!$this->uploaded) {
            $this->serveFallbackImage();
            return $this->failResult($this->error ?: 'Aucun fichier chargé.');
        }

        // Validation identique à process()
        if ($this->maxSize > 0 && $this->srcSize > $this->maxSize) {
            return $this->failResult(sprintf(
                'Fichier trop volumineux : %s (max autorisé : %s).',
                $this->formatSize($this->srcSize),
                $this->formatSize($this->maxSize),
            ));
        }

        if ($this->checkMime) {
            $mimeError = $this->validateMime();
            if ($mimeError !== null) {
                return $this->failResult($mimeError);
            }
        }

        [$destFilename] = $this->buildDestFilename();

        // Dossier système temporaire
        $tmpDir      = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
        $tmpPathname = $tmpDir . 'upl_serve_' . uniqid('', more_entropy: true)
            . '_' . $destFilename;

        $needsImageProcessing = $this->srcIsImage && (
            $this->doResize
            || $this->convertTo !== null
            || $this->manualCrop !== null
            || $this->manualPreCrop !== null
            || $this->flip !== null
            || $this->rotate !== null
            || ($this->autoRotate && $this->srcMime === 'image/jpeg')
            || $this->brightness !== null
            || $this->contrast !== null
            || $this->opacity !== null
            || $this->greyscale
            || $this->negative
        );

        $ok = $needsImageProcessing
            ? $this->processImage($tmpPathname)
            : copy($this->srcPathname, $tmpPathname);

        if (!$ok) {
            return $this->failResult($this->error ?: 'Échec du traitement pour le streaming.');
        }

        $this->processed = true;
        $result          = $this->buildResult($tmpPathname);

        // Stream vers le client, puis supprime le temporaire
        $this->streamToClient($tmpPathname, $result->mimeType, $result->filename);
        @unlink($tmpPathname);

        $this->resetProcessingOptions();

        // On retourne le résultat sans pathname disque (fichier supprimé)
        return new UploadResult(
            pathname:  '',
            filename:  $result->filename,
            nameBody:  $result->nameBody,
            extension: $result->extension,
            mimeType:  $result->mimeType,
            size:      $result->size,
            width:     $result->width,
            height:    $result->height,
            error:     null,
            success:   true,
        );
    }


    private function serveFallbackImage(): void
    {
        http_response_code(404);
        header('Content-Type: image/png');

        $img = imagecreatetruecolor(240, 240);
        $bg  = imagecolorallocate($img, 240, 240, 240);
        imagefill($img, 0, 0, $bg);

        imagestring($img, 5, 80, 120, 'Not Found', imagecolorallocate($img, 100, 100, 100));

        imagepng($img);
        exit;
    }

    /**
     * Émet les headers HTTP et envoie le contenu du fichier au navigateur.
     *
     * @param string $pathname    Chemin du fichier à servir.
     * @param string $mimeType   Type MIME (Content-Type).
     * @param string $filename   Nom suggéré pour Content-Disposition.
     */
    private function streamToClient(string $pathname, string $mimeType, string $filename): void
    {
        if (headers_sent()) {
            // Des headers ont déjà été envoyés (ex : output buffering désactivé)
            // On ne peut pas streamer proprement — on abandonne silencieusement.
            return;
        }

        $disposition = $this->browserOutput ?? 'inline';
        $name        = $this->browserName ?? $filename;

        // Encode le nom pour RFC 6266 (supporte UTF-8, espaces, caractères spéciaux)
        $encodedName = rawurlencode($name);
        $asciiName   = preg_replace('/[^\x20-\x7E]/', '_', $name);

        $size         = (int) filesize($pathname);
        $lastModified = gmdate('D, d M Y H:i:s \G\M\T', (int) filemtime($pathname));
        $etag         = '"' . md5($pathname . $size) . '"';

        // Support du cache conditionnel (304 Not Modified)
        $ifNoneMatch  = $_SERVER['HTTP_IF_NONE_MATCH']  ?? '';
        $ifModSince   = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

        if ($ifNoneMatch === $etag || $ifModSince === $lastModified) {
            http_response_code(304);
            return;
        }

        // Support du Range (téléchargements reprenables, lecture vidéo/audio)
        $rangeStart = 0;
        $rangeEnd   = $size - 1;
        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';

        if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $m)) {
            $rangeStart = $m[1] !== '' ? (int) $m[1] : 0;
            $rangeEnd   = $m[2] !== '' ? (int) $m[2] : $size - 1;
            $rangeEnd   = min($rangeEnd, $size - 1);
            $rangeStart = max(0, min($rangeStart, $rangeEnd));
            http_response_code(206);
        } else {
            http_response_code(200);
        }

        $contentLength = $rangeEnd - $rangeStart + 1;

        // Vide tout output buffering en cours avant d'envoyer le binaire
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mimeType);
        header(sprintf(
            'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $asciiName,
            $encodedName,
        ));
        header('Content-Length: ' . $contentLength);
        header('Accept-Ranges: bytes');
        header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $size);
        header('Last-Modified: ' . $lastModified);
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');

        // Désactive la limite de temps pour les gros fichiers
        set_time_limit(0);

        $fh = fopen($pathname, 'rb');

        if ($fh === false) {
            return;
        }

        if ($rangeStart > 0) {
            fseek($fh, $rangeStart);
        }

        $remaining = $contentLength;
        $chunkSize = 1024 * 256; // 256 Ko par chunk

        while (!feof($fh) && $remaining > 0) {
            $chunk = fread($fh, min($chunkSize, $remaining));
            if ($chunk === false) break;
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }

        fclose($fh);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Traitement d'image (privé)
    // ═════════════════════════════════════════════════════════════════════════

    private function processImage(string $destPathname): bool
    {
        if (!extension_loaded('gd')) {
            $this->error = 'L\'extension GD est requise pour le traitement d\'images.';
            return false;
        }

        $src = $this->gdLoad($this->srcPathname, $this->srcMime);

        if ($src === null) {
            $this->error = 'Impossible de lire l\'image source.';
            return false;
        }

        // Auto-rotation EXIF (JPEG uniquement)
        if ($this->autoRotate && $this->srcMime === 'image/jpeg' && function_exists('exif_read_data')) {
            $src = $this->applyExifRotation($src, $this->srcPathname);
        }

        // Pre-crop (avant resize)
        if ($this->manualPreCrop !== null) {
            $src = $this->applyPreCrop($src);
        }

        // Calcul et application du resize
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        [$dstW, $dstH] = $this->computeOutputDimensions($srcW, $srcH);
        $dst = $this->applyResize($src, $srcW, $srcH, $dstW, $dstH);

        // Crop manuel post-resize
        if ($this->manualCrop !== null) {
            $dst = $this->applyManualCrop($dst);
        }

        // Transformations géométriques
        if ($this->flip !== null) {
            $this->applyFlip($dst);
        }
        if ($this->rotate !== null && $this->rotate % 360 !== 0) {
            $dst = $this->applyRotation($dst);
        }

        // Corrections colorimétriques
        if ($this->greyscale) {
            imagefilter($dst, IMG_FILTER_GRAYSCALE);
        }
        if ($this->negative) {
            imagefilter($dst, IMG_FILTER_NEGATE);
        }
        if ($this->brightness !== null) {
            imagefilter($dst, IMG_FILTER_BRIGHTNESS, $this->brightness);
        }
        if ($this->contrast !== null) {
            // GD inverse le sens du contraste : on inverse le signe
            imagefilter($dst, IMG_FILTER_CONTRAST, -$this->contrast);
        }
        if ($this->opacity !== null && $this->opacity < 100) {
            $dst = $this->applyOpacity($dst, $this->opacity);
        }

        $format  = $this->resolveOutputFormat();

        $hasAlpha = in_array($this->srcMime, [
            'image/png',
            'image/webp',
            'image/gif'
        ], true);

        if ($format === ImageFormat::Jpeg && $hasAlpha) {
            $dst = $this->flattenToBackground($dst);
        }

        $success = $this->gdSave($dst, $destPathname, $format);

        if (!$success) {
            $this->error = "Impossible d'enregistrer l'image au format « {$format->value} ».";
        }

        return $success;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GD helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function gdLoad(string $path, string $mime): ?\GdImage
    {
        $img = match ($mime) {
            'image/jpeg', 'image/pjpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png',  'image/x-png'               => @imagecreatefrompng($path),
            'image/gif'                                => @imagecreatefromgif($path),
            'image/webp', 'image/x-webp'              => @imagecreatefromwebp($path),
            'image/bmp',  'image/x-bmp',
            'image/x-ms-bmp', 'image/x-windows-bmp'  => @imagecreatefrombmp($path),
            default                                    => false,
        };

        return ($img instanceof \GdImage) ? $img : null;
    }

    private function gdSave(\GdImage $img, string $path, ImageFormat $format): bool
    {
        if ($this->interlace) {
            imageinterlace($img, true);
        }

        imagesavealpha($img, true);

        return match ($format) {
            ImageFormat::Jpeg => imagejpeg($img, $path, $this->resolveJpegQuality($img)),
            ImageFormat::Png  => imagepng($img, $path, $this->pngCompression ?? -1),
            ImageFormat::Gif  => imagegif($img, $path),
            ImageFormat::Webp => imagewebp($img, $path, $this->webpQuality),
            ImageFormat::Bmp  => imagebmp($img, $path),
        };
    }

    private function newCanvas(int $w, int $h): \GdImage
    {
        $img = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagealphablending($img, false);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $transparent);
        imagesavealpha($img, true);
        return $img;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Calcul des dimensions
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{int, int} [$dstW, $dstH] */
    private function computeOutputDimensions(int $srcW, int $srcH): array
    {
        if (!$this->doResize) {
            return [$srcW, $srcH];
        }

        $tw = $this->targetWidth;
        $th = $this->targetHeight;

        [$dstW, $dstH] = match ($this->resizeMode) {
            ResizeMode::Stretch => [$tw, $th],

            ResizeMode::Width   => [$tw, (int) round($srcH * $tw / $srcW)],

            ResizeMode::Height  => [(int) round($srcW * $th / $srcH), $th],

            ResizeMode::Pixels  => (static function () use ($srcW, $srcH, $tw, $th): array {
                $factor = sqrt(($tw * $th) / ($srcW * $srcH));
                return [(int) round($srcW * $factor), (int) round($srcH * $factor)];
            })(),

            // Fit & Fill : ratio conservé, on scale au facteur minimum (fit dans la boîte)
            ResizeMode::Fit, ResizeMode::Fill => (static function () use ($srcW, $srcH, $tw, $th): array {
                $factor = min($tw / $srcW, $th / $srcH);
                return [(int) round($srcW * $factor), (int) round($srcH * $factor)];
            })(),
        };

        if ($this->noEnlarging && ($dstW > $srcW || $dstH > $srcH)) {
            return [$srcW, $srcH];
        }
        if ($this->noShrinking && ($dstW < $srcW || $dstH < $srcH)) {
            return [$srcW, $srcH];
        }

        return [max(1, $dstW), max(1, $dstH)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Opérations image
    // ─────────────────────────────────────────────────────────────────────────

    private function applyResize(\GdImage $src, int $srcW, int $srcH, int $dstW, int $dstH): \GdImage
    {
        if ($this->resizeMode === ResizeMode::Fill && $this->doResize) {
            return $this->applyFillResize($src, $srcW, $srcH);
        }

        if ($srcW === $dstW && $srcH === $dstH) {
            return $src;
        }

        $dst = $this->newCanvas($dstW, $dstH);
        imagealphablending($dst, false);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagesavealpha($dst, true);

        return $dst;
    }

    /**
     * Mode Fill : scale pour couvrir la boîte entière, puis recadre l'excédent.
     */
    private function applyFillResize(\GdImage $src, int $srcW, int $srcH): \GdImage
    {
        $tw = $this->targetWidth;
        $th = $this->targetHeight;

        $factor  = max($tw / $srcW, $th / $srcH);
        $scaledW = (int) round($srcW * $factor);
        $scaledH = (int) round($srcH * $factor);

        $scaled = $this->newCanvas($scaledW, $scaledH);
        imagealphablending($scaled, false);
        imagecopyresampled($scaled, $src, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

        [$ox, $oy] = $this->anchorOffsets($scaledW, $scaledH, $tw, $th);

        $dst = $this->newCanvas($tw, $th);
        imagealphablending($dst, false);
        imagecopy($dst, $scaled, 0, 0, $ox, $oy, $tw, $th);
        imagesavealpha($dst, true);

        return $dst;
    }

    private function applyPreCrop(\GdImage $src): \GdImage
    {
        [$top, $right, $bottom, $left] = $this->expandOffsets(
            $this->manualPreCrop,
            imagesx($src),
            imagesy($src),
        );

        $newW = max(1, imagesx($src) - $left - $right);
        $newH = max(1, imagesy($src) - $top  - $bottom);

        $dst = $this->newCanvas($newW, $newH);
        imagecopy($dst, $src, 0, 0, $left, $top, $newW, $newH);

        return $dst;
    }

    private function applyManualCrop(\GdImage $src): \GdImage
    {
        [$x, $y, $w, $h] = $this->manualCrop;

        $w = min($w, imagesx($src) - $x);
        $h = min($h, imagesy($src) - $y);

        $dst = $this->newCanvas(max(1, $w), max(1, $h));
        imagecopy($dst, $src, 0, 0, $x, $y, $w, $h);

        return $dst;
    }

    private function applyFlip(\GdImage $img): void
    {
        match ($this->flip) {
            FlipDirection::Horizontal => imageflip($img, IMG_FLIP_HORIZONTAL),
            FlipDirection::Vertical   => imageflip($img, IMG_FLIP_VERTICAL),
            FlipDirection::Both       => imageflip($img, IMG_FLIP_BOTH),
            null                      => null,
        };
    }

    private function applyRotation(\GdImage $img): \GdImage
    {
        // GD imagerotate tourne dans le sens anti-horaire → on inverse
        $rotated = imagerotate($img, -(int) $this->rotate, imagecolorallocatealpha($img, 0, 0, 0, 127));

        if (!($rotated instanceof \GdImage)) {
            return $img;
        }

        imagesavealpha($rotated, true);
        return $rotated;
    }

    private function applyExifRotation(\GdImage $img, string $path): \GdImage
    {
        try {
            $exif        = @exif_read_data($path);
            $orientation = (int) ($exif['Orientation'] ?? 1);

            $rotated = match ($orientation) {
                3 => imagerotate($img, 180, 0),
                6 => imagerotate($img, -90, 0),
                8 => imagerotate($img,  90, 0),
                default => null,
            };

            if ($rotated instanceof \GdImage) {
                imagesavealpha($rotated, true);
                return $rotated;
            }
        } catch (\Throwable) {
            // EXIF illisible : on continue sans rotation
        }

        return $img;
    }

    private function applyOpacity(\GdImage $src, int $opacity): \GdImage
    {
        $w   = imagesx($src);
        $h   = imagesy($src);
        $dst = $this->newCanvas($w, $h);

        // On copie pixel par pixel pour modifier le canal alpha
        imagealphablending($dst, false);

        $alphaFactor = (100 - $opacity) / 100 * 127;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba  = imagecolorat($src, $x, $y);
                $alpha = (($rgba >> 24) & 0x7F);
                $newAlpha = min(127, (int) ($alpha + $alphaFactor * (1 - $alpha / 127)));
                $color = imagecolorallocatealpha(
                    $dst,
                    ($rgba >> 16) & 0xFF,
                    ($rgba >> 8)  & 0xFF,
                    $rgba         & 0xFF,
                    $newAlpha,
                );
                imagesetpixel($dst, $x, $y, $color);
            }
        }

        imagesavealpha($dst, true);
        return $dst;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers image
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{int, int} [offsetX, offsetY] */
    private function anchorOffsets(int $srcW, int $srcH, int $dstW, int $dstH): array
    {
        $a = $this->cropAnchor->value;

        $ox = str_contains($a, 'L') ? 0
            : (str_contains($a, 'R') ? $srcW - $dstW
            : intdiv($srcW - $dstW, 2));

        $oy = str_contains($a, 'T') ? 0
            : (str_contains($a, 'B') ? $srcH - $dstH
            : intdiv($srcH - $dstH, 2));

        return [max(0, $ox), max(0, $oy)];
    }

    private function resolveOutputFormat(): ImageFormat
    {
        return $this->convertTo
            ?? ImageFormat::fromMime($this->srcMime)
            ?? ImageFormat::fromExtension($this->srcNameExt)
            ?? ImageFormat::Jpeg;
    }

    /**
     * Détermine la qualité JPEG optimale pour atteindre jpegTargetSize.
     * Utilise une recherche binaire (8 itérations ≈ précision à 0,4%).
     */
    private function resolveJpegQuality(\GdImage $img): int
    {
        if ($this->jpegTargetSize === null) {
            return $this->jpegQuality;
        }

        $lo = 1; $hi = 100; $best = $this->jpegQuality;

        for ($i = 0; $i < 8; $i++) {
            $mid = intdiv($lo + $hi, 2);
            ob_start();
            imagejpeg($img, null, $mid);
            $size = strlen((string) ob_get_clean());

            if ($size <= $this->jpegTargetSize) {
                $best = $mid;
                $lo   = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $best;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Détection MIME
    // ═════════════════════════════════════════════════════════════════════════

    private function detectMime(): void
    {
        $mime = null;

        // 1. finfo — méthode la plus fiable (PHP 8.1+ : classe objet)
        if (class_exists(\finfo::class)) {
            $fi      = new \finfo(FILEINFO_MIME_TYPE);
            $result  = $fi->file($this->srcPathname);
            if ($result !== false && str_contains($result, '/')) {
                $mime = $result;
            }
        }

        // 2. Commande UNIX file() (non-Windows uniquement)
        if ($mime === null && PHP_OS_FAMILY !== 'Windows' && function_exists('exec')) {
            $output = [];
            @exec('file -bi ' . escapeshellarg($this->srcPathname), $output);
            $raw = trim($output[0] ?? '');
            if ($raw !== '' && preg_match('#^([\w.+-]+/[\w.+-]+)#', $raw, $m)) {
                $mime = $m[1];
            }
        }

        // 3. getimagesize() pour les images
        if ($mime === null) {
            $info = @getimagesize($this->srcPathname);
            if (is_array($info) && isset($info['mime'])) {
                $mime = $info['mime'];
            }
        }

        // 4. Déduction par extension
        if ($mime === null && $this->srcNameExt !== '') {
            $mime = $this->mimeMap[$this->srcNameExt] ?? null;
        }

        $this->srcMime = $mime ?? '';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Validation MIME
    // ═════════════════════════════════════════════════════════════════════════

    private function validateMime(): ?string
    {
        if ($this->srcMime === '' || !str_contains($this->srcMime, '/')) {
            return 'Type MIME indétectable.';
        }

        [$type, $subtype] = explode('/', $this->srcMime, 2);

        $allowed = false;

        foreach ($this->allowed as $pattern) {
            [$pt, $ps] = [...explode('/', $pattern, 2), ''];
            if (($pt === '*' || $pt === $type) && ($ps === '*' || $ps === $subtype)) {
                $allowed = true;
                break;
            }
        }

        foreach ($this->forbidden as $pattern) {
            [$pt, $ps] = [...explode('/', $pattern, 2), ''];
            if (($pt === '*' || $pt === $type) && ($ps === '*' || $ps === $subtype)) {
                $allowed = false;
                break;
            }
        }

        return $allowed ? null : "Type de fichier non autorisé : « {$this->srcMime} ».";
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Nom de fichier & filesystem
    // ═════════════════════════════════════════════════════════════════════════

    private function parseName(string $name): void
    {
        $name = basename($name);

        if (preg_match('/^(.+)\.([^.]+)$/', $name, $m)) {
            $this->srcNameBody = $m[1];
            $this->srcNameExt  = strtolower($m[2]);
        } else {
            $this->srcNameBody = $name;
            $this->srcNameExt  = '';
        }

        $this->srcName = $name;
    }

    /** @return array{string, string}  [filename_with_ext, extension] */
    private function buildDestFilename(): array
    {
        $body = $this->newNameBody ?? $this->srcNameBody;
        $ext  = $this->newNameExt
            ?? $this->convertTo?->value
            ?? $this->srcNameExt;

        if ($this->nameBodyPrefix !== null) {
            $body = $this->nameBodyPrefix . $body;
        }
        if ($this->nameBodySuffix !== null) {
            $body .= $this->nameBodySuffix;
        }

        // Protection anti-script
        if ($this->noScript) {
            $mimeIsScript = str_starts_with($this->srcMime, 'text/')
                || str_contains($this->srcMime, 'javascript');
            $extIsScript  = in_array(strtolower($ext), $this->scriptBlacklist, strict: true);

            if ($mimeIsScript || $extIsScript) {
                $body = $body . ($ext !== '' ? '.' . $ext : '');
                $ext  = 'txt';
            }
        }

        // Force extension
        if ($this->forceExtension && $ext === '') {
            $ext = $this->srcIsImage
                ? (ImageFormat::fromMime($this->srcMime)?->value ?? 'bin')
                : 'bin';
        }

        if ($this->safeName) {
            $body = $this->sanitize($body);
        }

        $filename = $body . ($ext !== '' ? '.' . $ext : '');

        return [$filename, $ext];
    }

    private function resolveCollision(string $dir, string $filename, string $ext): string
    {
        $path = $dir . $filename;

        if (!file_exists($path) || $this->overwrite) {
            return $path;
        }

        if (!$this->autoRename) {
            $this->error = "Le fichier « {$filename} » existe déjà.";
            return $path;
        }

        $body    = pathinfo($filename, PATHINFO_FILENAME);
        $extPart = $ext !== '' ? '.' . $ext : '';
        $i       = 1;

        do {
            $path = $dir . $body . '_' . $i . $extPart;
            $i++;
        } while (file_exists($path));

        return $path;
    }

    private function ensureDir(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, $this->dirChmod, recursive: true);
    }

    private function sanitize(string $name): string
    {
        // Supprime balises HTML
        $name = strip_tags($name);

        // Supprime caractères de contrôle (0x00–0x1F, 0x7F)
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        // Remplace caractères dangereux par un tiret
        $name = str_replace(
            ['?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',',
             "'", '"', '&', '%20', '+', '$', '#', '*',
             '(', ')', '|', '~', '`', '!', '{', '}', '%', '^'],
            '-',
            $name,
        );

        // Translittération (accents → ASCII) si disponible
        if (function_exists('transliterator_transliterate')) {
            $name = (string) transliterator_transliterate(
                'Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove',
                $name,
            );
        }

        // Normalise espaces et tirets multiples
        $name = (string) preg_replace('/[\r\n\t ]+/', '-', $name);
        $name = (string) preg_replace('/-{2,}/', '-', $name);
        $name = (string) preg_replace('/\.{2,}/', '.', $name);

        return trim($name, '.-_');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers internes
    // ─────────────────────────────────────────────────────────────────────────

    private function readImageDimensions(): void
    {
        $format = ImageFormat::fromMime($this->srcMime)
               ?? ImageFormat::fromExtension($this->srcNameExt);

        $this->srcIsImage = ($format !== null);

        if ($this->srcIsImage) {
            $info = @getimagesize($this->srcPathname);
            if (is_array($info)) {
                $this->srcWidth  = $info[0];
                $this->srcHeight = $info[1];
            }
        }
    }

    private function storeTemp(string $data, string $filename): static
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl_');

        if ($tmp === false || file_put_contents($tmp, $data) === false) {
            return $this->fail('Impossible de créer le fichier temporaire.');
        }

        $this->srcPathname = $tmp;
        $this->srcSize     = strlen($data);
        $this->srcIsTemp   = true;
        $this->uploaded    = true;

        $this->parseName($filename);
        $this->detectMime();
        $this->readImageDimensions();

        return $this;
    }

    private function fail(string $message): static
    {
        $this->uploaded = false;
        $this->error    = $message;
        return $this;
    }

    private function failResult(string $message): UploadResult
    {
        $this->error     = $message;
        $this->processed = false;

        return new UploadResult(
            pathname:  '',
            filename:  '',
            nameBody:  '',
            extension: '',
            mimeType:  $this->srcMime,
            size:      0,
            width:     null,
            height:    null,
            error:     $message,
            success:   false,
        );
    }

    private function buildResult(string $pathname): UploadResult
    {
        $info = @getimagesize($pathname);

        return new UploadResult(
            pathname:  $pathname,
            filename:  basename($pathname),
            nameBody:  pathinfo($pathname, PATHINFO_FILENAME),
            extension: strtolower(pathinfo($pathname, PATHINFO_EXTENSION)),
            mimeType:  $this->srcMime,
            size:      (int) filesize($pathname),
            width:     $info[0] ?? null,
            height:    $info[1] ?? null,
            error:     null,
            success:   true,
        );
    }

    private function reset(): void
    {
        $this->clean();
        $this->srcName     = '';
        $this->srcNameBody = '';
        $this->srcNameExt  = '';
        $this->srcMime     = '';
        $this->srcSize     = 0;
        $this->srcIsImage  = false;
        $this->srcWidth    = null;
        $this->srcHeight   = null;
        $this->uploaded    = false;
        $this->processed   = false;
        $this->error       = '';
        $this->resetProcessingOptions();
    }

    /** Remet à zéro les options de traitement après chaque process(). */
    private function resetProcessingOptions(): void
    {
        $this->newNameBody      = null;
        $this->newNameExt       = null;
        $this->nameBodyPrefix   = null;
        $this->nameBodySuffix   = null;
        $this->safeName         = true;
        $this->forceExtension   = true;
        $this->overwrite        = false;
        $this->autoRename       = true;
        $this->noScript         = true;
        $this->doResize         = false;
        $this->targetWidth      = 150;
        $this->targetHeight     = 150;
        $this->resizeMode       = ResizeMode::Fit;
        $this->noEnlarging      = false;
        $this->noShrinking      = false;
        $this->cropAnchor       = CropAnchor::Center;
        $this->manualCrop       = null;
        $this->manualPreCrop    = null;
        $this->convertTo        = null;
        $this->jpegQuality      = 85;
        $this->jpegTargetSize   = null;
        $this->webpQuality      = 85;
        $this->pngCompression   = null;
        $this->interlace        = false;
        $this->flip             = null;
        $this->rotate           = null;
        $this->autoRotate       = true;
        $this->brightness       = null;
        $this->contrast         = null;
        $this->opacity          = null;
        $this->greyscale        = false;
        $this->negative         = false;
        $this->browserOutput    = null;
        $this->browserName      = null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utilitaires
    // ─────────────────────────────────────────────────────────────────────────

    private function parseSize(string $size): int
    {
        $size = trim($size);
        $last = strtolower(substr($size, -1));
        $val  = (int) $size;

        return $val * match ($last) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }

    private function formatSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => round($bytes / 1024 ** 3, 2) . ' Go',
            $bytes >= 1024 ** 2 => round($bytes / 1024 ** 2, 2) . ' Mo',
            $bytes >= 1024      => round($bytes / 1024, 2) . ' Ko',
            default             => $bytes . ' o',
        };
    }

    /**
     * Convertit un tableau d'offsets CSS-like en [top, right, bottom, left] (px).
     *
     * @param array $raw   [all] | [vertical, horizontal] | [top, right, bottom, left]
     * @param int   $refW  Largeur de référence pour les calculs en %
     * @param int   $refH  Hauteur de référence pour les calculs en %
     * @return array{int, int, int, int}
     */
    private function expandOffsets(array $raw, int $refW, int $refH): array
    {
        [$t, $r, $b, $l] = match (count($raw)) {
            4       => $raw,
            2       => [$raw[0], $raw[1], $raw[0], $raw[1]],
            default => [$raw[0], $raw[0], $raw[0], $raw[0]],
        };

        $parse = static function (mixed $v, int $ref): int {
            $s = (string) $v;
            if (str_contains($s, '%')) {
                return (int) round((float) $s * $ref / 100);
            }
            return (int) $s;
        };

        return [
            $parse($t, $refH),
            $parse($r, $refW),
            $parse($b, $refH),
            $parse($l, $refW),
        ];
    }

    private function phpUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse upload_max_filesize (php.ini).',
            UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse MAX_FILE_SIZE du formulaire HTML.',
            UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement transféré.',
            UPLOAD_ERR_NO_FILE    => 'Aucun fichier n\'a été envoyé.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant.',
            UPLOAD_ERR_CANT_WRITE => 'Échec d\'écriture sur le disque.',
            UPLOAD_ERR_EXTENSION  => 'Upload stoppé par une extension PHP.',
            default               => "Erreur d'upload inconnue (code {$code}).",
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Données de référence (statiques)
    // ─────────────────────────────────────────────────────────────────────────

    private static function defaultAllowedMimes(): array
    {
        return [
            'image/*', 'audio/*', 'video/*',
            'application/pdf',
            'application/zip', 'application/x-zip', 'application/x-zip-compressed',
            'application/gzip', 'application/x-gzip',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv', 'text/rtf',
        ];
    }

    private static function defaultMimeMap(): array
    {
        return [
            'jpg'  => 'image/jpeg',   'jpeg' => 'image/jpeg',
            'jpe'  => 'image/jpeg',   'png'  => 'image/png',
            'gif'  => 'image/gif',    'webp' => 'image/webp',
            'bmp'  => 'image/bmp',    'pdf'  => 'application/pdf',
            'zip'  => 'application/zip',
            'txt'  => 'text/plain',   'csv'  => 'text/csv',
            'mp3'  => 'audio/mpeg',   'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
    }
}