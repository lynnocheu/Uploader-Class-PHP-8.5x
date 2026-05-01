# Uploader — Guide complet

> Classe PHP 8.4/8.5 pour l'upload, le traitement d'images et le streaming navigateur.

---

## Table des matières

1. [Installation & prérequis](#1-installation--prérequis)
2. [Chargement des sources](#2-chargement-des-sources)
3. [Validation & sécurité](#3-validation--sécurité)
4. [Nommage & gestion des fichiers](#4-nommage--gestion-des-fichiers)
5. [Traitement des images](#5-traitement-des-images)
   - [Resize](#51-resize)
   - [Crop](#52-crop)
   - [Conversion de format](#53-conversion-de-format)
   - [Transformations géométriques](#54-transformations-géométriques)
   - [Corrections colorimétriques](#55-corrections-colorimétriques)
   - [Qualité & compression](#56-qualité--compression)
   - [Fond de conversion (flattenBackground)](#57-fond-de-conversion-flattenbackground)
6. [Streaming navigateur](#6-streaming-navigateur)
   - [display() & download()](#61-display--download)
   - [serve() — streaming pur](#62-serve--streaming-pur)
   - [Fallback — image de remplacement](#63-fallback--image-de-remplacement)
7. [Multi-output (traitement multiple)](#7-multi-output-traitement-multiple)
8. [Lecture des résultats](#8-lecture-des-résultats)
9. [Recettes pratiques](#9-recettes-pratiques)
10. [Référence des enums](#10-référence-des-enums)
11. [Référence des méthodes](#11-référence-des-méthodes)

---

## 1. Installation & prérequis

### Extensions PHP requises

| Extension | Rôle | Obligatoire |
|-----------|------|:-----------:|
| `gd` | Traitement d'images (resize, crop, conversion…) | Oui (si images) |
| `fileinfo` | Détection MIME fiable | Recommandé |
| `exif` | Auto-rotation photos mobiles | Optionnel |
| `intl` | Translittération des noms de fichiers | Optionnel |

### Vérification rapide

```bash
php -m | grep -E "gd|fileinfo|exif|intl"
```

### Inclusion

```php
<?php
use Claude\Upload\Uploader;
use Claude\Upload\ImageFormat;
use Claude\Upload\ResizeMode;
use Claude\Upload\CropAnchor;
use Claude\Upload\FlipDirection;

require 'Uploader.php';
```

---

## 2. Chargement des sources

L'`Uploader` accepte quatre origines différentes, toutes interchangeables avec le même pipeline.

### Formulaire HTML (`$_FILES`)

Le cas d'usage le plus courant. La méthode vérifie automatiquement `is_uploaded_file()`.

```php
$uploader = new Uploader();
$uploader->fromFiles($_FILES['photo']);
```

**Formulaire HTML correspondant :**

```html
<form method="post" enctype="multipart/form-data">
    <input type="file" name="photo" accept="image/*">
    <button type="submit">Envoyer</button>
</form>
```

---

### Upload AJAX / XHR (flux binaire)

Lorsque le fichier est envoyé en `Content-Type: application/octet-stream`
(axios, fetch, jQuery File Upload…).

```php
// Côté PHP
$uploader = new Uploader();
$uploader->fromStream(
    filename: $_SERVER['HTTP_X_FILE_NAME'] ?? 'upload'
);
```

```javascript
// Côté JavaScript (fetch)
const file = document.querySelector('input[type="file"]').files[0];

fetch('/upload.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/octet-stream',
        'X-File-Name': file.name,
    },
    body: file,
});
```

---

### Chaîne Base64

Formats acceptés sans configuration supplémentaire :

```php
$uploader = new Uploader();

// Data URI (canvas, API, mobile…)
$uploader->fromBase64('data:image/png;base64,iVBORw0KGgo...', 'capture.png');

// Préfixe simple
$uploader->fromBase64('base64,iVBORw0KGgo...', 'photo.jpg');

// Raw Base64
$uploader->fromBase64('iVBORw0KGgoAAAANSUhEUgAA...', 'image.jpg');
```

> **Nettoyage :** Appelez toujours `$uploader->clean()` après les `process()` pour
> supprimer le fichier temporaire créé en coulisse par `fromBase64()` et `fromStream()`.

---

### Fichier déjà sur disque

Pour retraiter un fichier existant, générer des thumbnails depuis une bibliothèque, etc.

```php
$uploader = new Uploader();
$uploader->fromPath('/var/www/storage/original/photo.jpg');
```

---

## 3. Validation & sécurité

### Taille maximale

```php
$uploader
    ->fromFiles($_FILES['doc'])
    ->setMaxSize('10M')       // notation courte : K, M, G
    ->setMaxSize(10_485_760)  // ou en octets
    ->process('/uploads/');
```

La valeur par défaut est calquée sur `upload_max_filesize` de `php.ini`.

---

### Contrôle des types MIME

```php
// N'accepter que les images
$uploader->allowOnly('image/*');

// Accepter images + PDF
$uploader->allowOnly(['image/*', 'application/pdf']);

// Ajouter un type à la liste existante
$uploader->allow('video/mp4');

// Interdire un type (prioritaire sur allow)
$uploader->deny('image/svg+xml');

// Désactiver la vérification MIME (déconseillé)
$uploader->checkMime(false);
```

Les wildcards sont supportés : `image/*`, `video/*`, `*/*`.

---

### Protection anti-scripts

Activée par défaut. Bloque les extensions exécutables (`php`, `py`, `sh`, `js`, etc.)
et les fichiers dont le MIME est `text/*` ou `*javascript*` en les renommant en `.txt`.

```php
$uploader->noScript(true);   // activé (défaut)
$uploader->noScript(false);  // désactiver (déconseillé en prod)
```

---

## 4. Nommage & gestion des fichiers

### Remplacement du nom

```php
// Remplace corps + extension
$uploader->setName('avatar', 'webp');

// Corps seulement (extension conservée)
$uploader->setName('profil');

// Extension seulement
$uploader->setExtension('jpg');

// Préfixe / suffixe
$uploader->setPrefix('thumb_');
$uploader->setSuffix('_' . date('Ymd'));
```

### Gestion des collisions

```php
// Écrase le fichier existant
$uploader->overwrite(true);

// Renomme automatiquement : foo.jpg → foo_1.jpg → foo_2.jpg…
$uploader->autoRename(true);   // défaut

// Retourne une erreur si le fichier existe
$uploader->overwrite(false)->autoRename(false);
```

### Nettoyage du nom (sanitization)

Activé par défaut. Supprime les caractères dangereux, translittère les accents,
normalise les espaces en tirets.

```php
$uploader->safeName(true);   // défaut
$uploader->safeName(false);  // conserve le nom brut
```

`"Été à Montréal (2).JPG"` → `"Ete-a-Montreal-2.JPG"` avec `safeName(true)`.

---

## 5. Traitement des images

### 5.1 Resize

La méthode `resize()` est le point d'entrée central. Elle prend trois arguments :
la largeur cible, la hauteur cible et le mode de redimensionnement.

#### Modes disponibles

| Mode | Comportement |
|------|-------------|
| `ResizeMode::Fit` | Contient l'image dans la boîte, ratio conservé, pas de rognage (**défaut**) |
| `ResizeMode::Fill` | Couvre entièrement la boîte, ratio conservé, l'excédent est rogné |
| `ResizeMode::Stretch` | Étire exactement aux dimensions cibles, ratio ignoré |
| `ResizeMode::Width` | Contraint la largeur uniquement, hauteur calculée |
| `ResizeMode::Height` | Contraint la hauteur uniquement, largeur calculée |
| `ResizeMode::Pixels` | Cible un nombre de pixels total, ratio conservé |

```php
// Miniature 200×200 sans distorsion (avec bandes transparentes si besoin)
$uploader->resize(200, 200, ResizeMode::Fit);

// Avatar carré 200×200 (recadrage centré automatique)
$uploader->resize(200, 200, ResizeMode::Fill);

// Largeur 800px, hauteur proportionnelle
$uploader->resize(800, 0, ResizeMode::Width);

// Cible ~1 mégapixel, ratio conservé
$uploader->resize(1000, 1000, ResizeMode::Pixels);
```

#### Garde-fous

```php
// Ne jamais agrandir une image plus petite que la cible
$uploader->resize(1920, 1080)->noEnlarging();

// Ne jamais réduire une image plus grande que la cible
$uploader->resize(400, 400)->noShrinking();
```

#### Ancrage du crop en mode Fill

```php
$uploader
    ->resize(200, 200, ResizeMode::Fill)
    ->cropAnchor(CropAnchor::Top);   // conserve la partie haute
```

Options : `Center` (défaut), `Top`, `Bottom`, `Left`, `Right`,
`TopLeft`, `TopRight`, `BottomLeft`, `BottomRight`.

---

### 5.2 Crop

Deux types de rognage complémentaires :

#### Pre-crop — rogne les bords **avant** le resize

```php
// Enlève 20px de chaque côté (syntaxe CSS)
$uploader->preCrop(20);

// [vertical, horizontal]
$uploader->preCrop([10, 20]);

// [top, right, bottom, left]
$uploader->preCrop([10, 20, 10, 20]);

// Pourcentages
$uploader->preCrop(['5%', '10%', '5%', '10%']);
```

#### Crop manuel — zone précise **après** le resize

```php
// crop(x, y, largeur, hauteur) en pixels
$uploader->crop(50, 30, 400, 300);
```

#### Recette : suppression des bandes noires d'une vidéo

```php
$uploader
    ->fromPath('/tmp/screenshot.jpg')
    ->preCrop([60, 0])          // enlève 60px en haut et en bas
    ->resize(1280, 720)
    ->process('/uploads/');
```

---

### 5.3 Conversion de format

```php
use Claude\Upload\ImageFormat;

$uploader->convertTo(ImageFormat::Webp);  // .jpg → .webp
$uploader->convertTo(ImageFormat::Jpeg);
$uploader->convertTo(ImageFormat::Png);
$uploader->convertTo(ImageFormat::Gif);
$uploader->convertTo(ImageFormat::Bmp);
```

> L'extension du fichier de sortie est automatiquement mise à jour pour correspondre
> au format cible.

---

### 5.4 Transformations géométriques

#### Rotation

```php
$uploader->rotate(90);    // 90° sens horaire
$uploader->rotate(180);
$uploader->rotate(270);   // = -90°
$uploader->rotate(-90);   // sens anti-horaire
```

#### Auto-rotation EXIF

Activée par défaut pour les JPEG. Corrige automatiquement l'orientation
des photos prises en mode portrait ou paysage avec un smartphone.

```php
$uploader->autoRotate(true);   // défaut
$uploader->autoRotate(false);  // désactiver
```

#### Flip

```php
$uploader->flip(FlipDirection::Horizontal);   // miroir gauche-droite
$uploader->flip(FlipDirection::Vertical);     // miroir haut-bas
$uploader->flip(FlipDirection::Both);         // les deux
$uploader->flip(null);                        // désactiver
```

---

### 5.5 Corrections colorimétriques

```php
// Luminosité : -127 (très sombre) à +127 (très clair)
$uploader->brightness(30);

// Contraste : -127 (aplati) à +127 (accentué)
$uploader->contrast(20);

// Opacité globale : 0 (transparent) à 100 (opaque)
$uploader->opacity(75);

// Niveaux de gris
$uploader->greyscale();

// Négatif
$uploader->negative();
```

---

### 5.6 Qualité & compression

#### JPEG

```php
// Qualité fixe (1–100)
$uploader->jpegQuality(85);  // défaut

// Ajustement automatique pour atteindre une taille cible
// (recherche binaire en 8 passes, précision ~0,4%)
$uploader->jpegMaxSize('200K');   // ≤ 200 Ko
$uploader->jpegMaxSize('1M');     // ≤ 1 Mo
```

#### PNG

```php
// Niveau de compression zlib : 1 (rapide) à 9 (lent, fichier petit)
$uploader->pngCompression(6);

// Défaut zlib
$uploader->pngCompression(null);
```

#### WebP

```php
$uploader->webpQuality(85);  // défaut
```

#### Entrelacement

```php
// JPEG progressif (s'affiche floue puis nette)
// GIF animé multi-frame
$uploader->interlace();
```

---

### 5.7 Fond de conversion (flattenBackground)

JPEG et BMP ne supportent pas le canal alpha. Lors d'une conversion depuis un PNG
ou WebP avec transparence, les pixels transparents sont aplatis sur une couleur de fond.
Sans appel explicite, le fond est **blanc**.

```php
// Fond blanc (comportement par défaut)
$uploader
    ->convertTo(ImageFormat::Jpeg)
    ->flattenBackground('white');

// Fond noir
$uploader
    ->convertTo(ImageFormat::Jpeg)
    ->flattenBackground('black');

// Couleur personnalisée en RGB
$uploader
    ->convertTo(ImageFormat::Jpeg)
    ->flattenBackground([34, 85, 120]);

// Couleur hexadécimale (6 ou 3 caractères)
$uploader
    ->convertTo(ImageFormat::Jpeg)
    ->flattenBackground('#1a2b3c');

$uploader
    ->convertTo(ImageFormat::Jpeg)
    ->flattenBackground('#fff');
```

> **Remarque :** `flattenBackground()` n'a aucun effet si le format de sortie
> supporte la transparence (PNG, WebP, GIF). Il est silencieusement ignoré
> dans ces cas.

#### Recette — Logo PNG sur fond coloré converti en JPEG

```php
$result = (new Uploader())
    ->fromPath('/assets/logo.png')          // PNG avec fond transparent
    ->resize(600, 200, ResizeMode::Fit)
    ->convertTo(ImageFormat::Jpeg)
    ->flattenBackground('#0d1b2a')          // fond sombre personnalisé
    ->jpegQuality(92)
    ->process('/public/img/');
```

---

## 6. Streaming navigateur

Trois façons d'envoyer le fichier directement au navigateur,
sans redirection ni lien supplémentaire.

### 6.1 display() & download()

Ces deux méthodes s'enchaînent avant `process()` et déclenchent le streaming
**après** que le fichier a été enregistré sur disque.

```php
// Sauvegarde sur disque ET affiche dans le navigateur
$result = (new Uploader())
    ->fromFiles($_FILES['photo'])
    ->resize(1200, 900, ResizeMode::Fit)
    ->convertTo(ImageFormat::Webp)
    ->webpQuality(80)
    ->display()                      // ← inline
    ->process('/var/www/uploads/');
```

```php
// Sauvegarde ET force le téléchargement
$result = (new Uploader())
    ->fromPath('/var/exports/rapport.pdf')
    ->download('rapport-annuel-2024.pdf')
    ->process('/var/exports/');
```

---

### 6.2 serve() — streaming pur

Idéal pour les images à la volée, les API d'images, les previews.
**Aucun fichier n'est écrit de façon permanente** (sauf si `$destDir` est fourni).

```php
// Streaming inline — aucun fichier sauvegardé
(new Uploader())
    ->fromFiles($_FILES['avatar'])
    ->resize(200, 200, ResizeMode::Fill)
    ->convertTo(ImageFormat::Webp)
    ->serve();
```

```php
// Streaming + sauvegarde simultanés
(new Uploader())
    ->fromFiles($_FILES['photo'])
    ->resize(800, 600)
    ->convertTo(ImageFormat::Webp)
    ->serve('/var/www/uploads/');
```

```php
// Forcer le téléchargement sans sauvegarde
(new Uploader())
    ->fromPath('/tmp/export-' . $id . '.csv')
    ->serve(download: true, browserName: 'export.csv');
```

---

### 6.3 Fallback — image de remplacement

`serve()` active automatiquement un **fallback** : si la source est absente,
invalide ou que le traitement échoue, une image vide est diffusée à la place
d'une erreur HTTP ou d'une page blanche. Aucune configuration requise.

```php
// Si le fichier n'existe pas → PNG 1×1 transparent automatiquement
(new Uploader())
    ->fromPath('/uploads/' . $id . '.jpg')
    ->serve();
```

#### Personnaliser le placeholder

```php
// Placeholder gris neutre 400×300
(new Uploader())
    ->fromPath('/uploads/' . $id . '.jpg')
    ->fallback(400, 300, '#cccccc')
    ->serve();

// Fond noir aux dimensions attendues
(new Uploader())
    ->fromPath('/uploads/' . $id . '.jpg')
    ->fallback(800, 600, 'black')
    ->serve();

// Transparent (color omise)
(new Uploader())
    ->fromPath('/uploads/' . $id . '.jpg')
    ->fallback(400, 300)
    ->serve();

// RGB
(new Uploader())
    ->fromPath('/uploads/' . $id . '.jpg')
    ->fallback(400, 300, [240, 240, 240])
    ->serve();
```

#### Désactiver le fallback

Retourne un `UploadResult` avec `success = false` sans rien émettre.

```php
(new Uploader())
    ->fromPath('/uploads/' . $id . '.jpg')
    ->noFallback()
    ->serve();
```

#### Comportement selon la disponibilité de GD

| Situation | Résultat |
|-----------|----------|
| GD disponible + `fallback(w, h, color)` | PNG aux dimensions et couleur choisies |
| GD disponible + `fallback(w, h)` | PNG transparent aux dimensions choisies |
| GD absent | PNG 1×1 transparent encodé en dur (aucune dépendance) |

---

### Ce que le streaming gère automatiquement

| Fonctionnalité | Détail |
|----------------|--------|
| **HTTP 206 Range** | Reprises de téléchargement, lecture vidéo/audio native |
| **ETag + Last-Modified** | Cache conditionnel, réponse 304 si le fichier n'a pas changé |
| **RFC 6266** | Nom de fichier UTF-8 (`filename*=UTF-8''…`), accents supportés |
| **Output buffering** | Vide tous les niveaux `ob_*` avant l'envoi binaire |
| **Chunks 256 Ko** | Le fichier n'est jamais chargé entièrement en RAM |
| **`headers_sent()`** | Abandon propre si des headers ont déjà été émis |
| **`X-Content-Type-Options`** | Protection anti-MIME-sniffing |

---

## 7. Multi-output (traitement multiple)

L'`Uploader` garde le fichier source chargé entre les appels à `process()`.
Chaque appel repart des **options par défaut** et peut définir un pipeline entièrement
différent.

```php
$uploader = new Uploader();
$uploader->fromFiles($_FILES['photo']);

if (!$uploader->isUploaded()) {
    die($uploader->getError());
}

// 1. Thumbnail 200×200
$thumb = $uploader
    ->resize(200, 200, ResizeMode::Fill)
    ->setPrefix('thumb_')
    ->convertTo(ImageFormat::Webp)
    ->webpQuality(70)
    ->process('/uploads/thumbs/');

// 2. Version moyenne 800×600
$medium = $uploader
    ->resize(800, 600, ResizeMode::Fit)
    ->setPrefix('medium_')
    ->convertTo(ImageFormat::Webp)
    ->webpQuality(80)
    ->process('/uploads/medium/');

// 3. Original compressé, ratio conservé, ≤ 300 Ko
$large = $uploader
    ->jpegMaxSize('300K')
    ->setPrefix('large_')
    ->process('/uploads/large/');

// Nettoyage obligatoire après tous les process()
$uploader->clean();
```

> **Important :** Les options sont remises à zéro après **chaque** `process()`.
> Ce comportement est intentionnel pour permettre des configurations indépendantes
> sur le même fichier source.

---

## 8. Lecture des résultats

`process()` et `serve()` retournent un objet `UploadResult` **readonly**.

```php
$result = $uploader
    ->fromFiles($_FILES['photo'])
    ->resize(800, 600)
    ->process('/uploads/');

if ($result->success) {
    echo $result->pathname;   // /uploads/mon-image.webp
    echo $result->filename;   // mon-image.webp
    echo $result->nameBody;   // mon-image
    echo $result->extension;  // webp
    echo $result->mimeType;   // image/webp
    echo $result->size;       // 48320 (octets)
    echo $result->width;      // 800
    echo $result->height;     // 600
} else {
    echo $result->error;      // message d'erreur lisible
}
```

### Propriétés de `UploadResult`

| Propriété | Type | Description |
|-----------|------|-------------|
| `success` | `bool` | `true` si le traitement a réussi |
| `error` | `?string` | Message d'erreur (`null` si succès) |
| `pathname` | `string` | Chemin complet du fichier produit |
| `filename` | `string` | Nom du fichier avec extension |
| `nameBody` | `string` | Corps du nom sans extension |
| `extension` | `string` | Extension en minuscules, sans point |
| `mimeType` | `string` | Type MIME détecté |
| `size` | `int` | Taille en octets du fichier produit |
| `width` | `?int` | Largeur en pixels (`null` si non-image) |
| `height` | `?int` | Hauteur en pixels (`null` si non-image) |

---

### Métadonnées de la source

Disponibles après `fromFiles()` / `fromBase64()` / `fromPath()` / `fromStream()`.

```php
$uploader->fromFiles($_FILES['photo']);

echo $uploader->getSrcName();    // photo_vacances.jpg
echo $uploader->getSrcMime();    // image/jpeg
echo $uploader->getSrcSize();    // 2097152
echo $uploader->getSrcWidth();   // 4032
echo $uploader->getSrcHeight();  // 3024
var_dump($uploader->isImage());  // true
```

---

## 9. Recettes pratiques

### Avatar utilisateur (carré, WebP, multi-taille)

```php
$uploader = (new Uploader())
    ->fromFiles($_FILES['avatar'])
    ->allowOnly('image/*')
    ->setMaxSize('5M');

if (!$uploader->isUploaded()) {
    return ['error' => $uploader->getError()];
}

$base = $uploader->safeName()->setName('avatar_' . $userId);

// 512×512 — version HD
$hd = (clone $uploader)         // clone pour conserver la source
    ->resize(512, 512, ResizeMode::Fill)
    ->convertTo(ImageFormat::Webp)
    ->webpQuality(85)
    ->process('/uploads/avatars/');

// 64×64 — icône
$icon = (clone $uploader)
    ->resize(64, 64, ResizeMode::Fill)
    ->convertTo(ImageFormat::Webp)
    ->webpQuality(75)
    ->setPrefix('icon_')
    ->process('/uploads/avatars/');

$uploader->clean();
```

---

### Galerie photo (JPEG optimisé + thumbnail)

```php
function processGalleryUpload(array $file, int $albumId): array
{
    $dir = "/uploads/albums/{$albumId}/";

    $uploader = (new Uploader())
        ->fromFiles($file)
        ->allowOnly('image/*')
        ->setMaxSize('20M');

    if (!$uploader->isUploaded()) {
        return ['error' => $uploader->getError()];
    }

    // Grande version, max 2000px côté long
    $large = $uploader
        ->resize(2000, 2000, ResizeMode::Fit)
        ->noEnlarging()
        ->autoRotate()
        ->jpegQuality(88)
        ->process($dir . 'large/');

    // Thumbnail carré 320×320
    $thumb = $uploader
        ->resize(320, 320, ResizeMode::Fill)
        ->cropAnchor(CropAnchor::Center)
        ->convertTo(ImageFormat::Webp)
        ->webpQuality(75)
        ->setPrefix('thumb_')
        ->process($dir . 'thumbs/');

    $uploader->clean();

    return [
        'large' => $large->pathname,
        'thumb' => $thumb->pathname,
        'width' => $large->width,
        'height' => $large->height,
        'size'  => $large->size,
    ];
}
```

---

### API d'image dynamique (resize à la volée, sans écriture disque)

Le fallback remplace avantageusement le `http_response_code(404)` : le navigateur
reçoit toujours une image valide, ce qui évite les icônes d'image cassée dans
les galeries ou les listes de produits.

```php
// /api/image.php?id=42&w=400&h=300&fit=fill
$id  = (int) ($_GET['id'] ?? 0);
$w   = min((int) ($_GET['w'] ?? 800), 2000);   // max 2000px
$h   = min((int) ($_GET['h'] ?? 600), 2000);
$fit = $_GET['fit'] === 'fill' ? ResizeMode::Fill : ResizeMode::Fit;

(new Uploader())
    ->fromPath("/var/storage/originals/{$id}.jpg")
    ->resize($w, $h, $fit)
    ->convertTo(ImageFormat::Webp)
    ->webpQuality(82)
    ->fallback($w, $h, '#e8e8e8')   // placeholder gris aux dimensions demandées
    ->serve();                       // streame, rien n'est écrit sur disque
```

---

### Upload Base64 depuis canvas (signature, webcam…)

```php
// $_POST['image'] = "data:image/png;base64,iVBORw0KGgo..."
$uploader = (new Uploader())
    ->fromBase64($_POST['image'], 'signature.png')
    ->resize(600, 200, ResizeMode::Fit)
    ->noEnlarging()
    ->convertTo(ImageFormat::Png)
    ->setName('sig_' . $userId);

$result = $uploader->process('/uploads/signatures/');
$uploader->clean();

echo json_encode(['path' => $result->pathname]);
```

---

### Téléchargement sécurisé avec token

```php
// download.php?token=abc123
$token = $_GET['token'] ?? '';
$file  = resolveTokenToFile($token);  // votre logique

if (!$file || !file_exists($file['path'])) {
    http_response_code(404);
    exit;
}

// Vérifie les droits d'accès
if (!userCanDownload($file['id'], $_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

(new Uploader())
    ->fromPath($file['path'])
    ->checkMime(false)   // on fait confiance au fichier stocké
    ->serve(download: true, browserName: $file['original_name']);
```

---

### PNG avec transparence → JPEG (fond personnalisé)

```php
// Logo PNG transparent converti en JPEG avec fond de marque
$result = (new Uploader())
    ->fromPath('/assets/logo-transparent.png')
    ->resize(800, 400, ResizeMode::Fit)
    ->noEnlarging()
    ->convertTo(ImageFormat::Jpeg)
    ->flattenBackground('#0d1b2a')   // fond sombre de la charte graphique
    ->jpegQuality(90)
    ->process('/public/img/');

// Variante fond blanc pour impression
$result = (new Uploader())
    ->fromPath('/assets/logo-transparent.png')
    ->resize(800, 400, ResizeMode::Fit)
    ->convertTo(ImageFormat::Jpeg)
    ->flattenBackground('white')
    ->jpegQuality(95)
    ->setName('logo-print')
    ->process('/public/img/');
```

---

### Traitement d'image artistique (noir & blanc + contraste)

```php
$result = (new Uploader())
    ->fromPath('/uploads/portrait.jpg')
    ->resize(1200, 1200, ResizeMode::Fit)
    ->autoRotate()
    ->greyscale()
    ->contrast(35)
    ->brightness(10)
    ->convertTo(ImageFormat::Jpeg)
    ->jpegQuality(90)
    ->setName('portrait_bw')
    ->process('/uploads/processed/');
```

---

## 10. Référence des enums

### `ImageFormat`

```php
ImageFormat::Jpeg   // .jpg / image/jpeg
ImageFormat::Png    // .png / image/png
ImageFormat::Gif    // .gif / image/gif
ImageFormat::Webp   // .webp / image/webp
ImageFormat::Bmp    // .bmp / image/bmp
```

### `ResizeMode`

```php
ResizeMode::Fit      // Contient dans la boîte (défaut)
ResizeMode::Fill     // Couvre + recadre
ResizeMode::Stretch  // Étire (ratio ignoré)
ResizeMode::Width    // Largeur fixe, hauteur auto
ResizeMode::Height   // Hauteur fixe, largeur auto
ResizeMode::Pixels   // Nb de pixels total cible
```

### `CropAnchor`

```php
CropAnchor::Center      // Centre (défaut)
CropAnchor::Top         // Haut-centre
CropAnchor::Bottom      // Bas-centre
CropAnchor::Left        // Centre-gauche
CropAnchor::Right       // Centre-droit
CropAnchor::TopLeft
CropAnchor::TopRight
CropAnchor::BottomLeft
CropAnchor::BottomRight
```

### `FlipDirection`

```php
FlipDirection::Horizontal   // Miroir gauche-droite
FlipDirection::Vertical     // Miroir haut-bas
FlipDirection::Both         // Miroir dans les deux sens
```

---

## 11. Référence des méthodes

### Sources

| Méthode | Description |
|---------|-------------|
| `fromFiles(array $file)` | Charge depuis `$_FILES['field']` |
| `fromStream(string $filename)` | Charge depuis `php://input` (XHR raw) |
| `fromBase64(string $b64, string $filename)` | Charge depuis une chaîne Base64 |
| `fromPath(string $path)` | Charge un fichier existant sur disque |

### Pipeline de sortie

| Méthode | Description |
|---------|-------------|
| `process(string $destDir)` | Traite et enregistre dans `$destDir` |
| `display(?string $name)` | Active le streaming inline après `process()` |
| `download(?string $name)` | Active le force-download après `process()` |
| `serve(?string $destDir, bool $download, ?string $name)` | Streame (+ sauvegarde optionnelle) |
| `fallback(int $w, int $h, color)` | Configure le placeholder si source invalide |
| `noFallback()` | Désactive le fallback pour cet appel |
| `clean()` | Supprime le fichier temporaire (Base64/stream) |

### Nommage

| Méthode | Description |
|---------|-------------|
| `setName(string $body, ?string $ext)` | Corps du nom (+ extension optionnelle) |
| `setExtension(string $ext)` | Extension uniquement |
| `setPrefix(string $prefix)` | Préfixe du nom |
| `setSuffix(string $suffix)` | Suffixe du nom |
| `overwrite(bool)` | Écraser si existant |
| `autoRename(bool)` | Renommage auto en cas de collision |
| `safeName(bool)` | Nettoyage du nom |
| `noScript(bool)` | Bloquer les extensions de script |

### Validation

| Méthode | Description |
|---------|-------------|
| `setMaxSize(int\|string)` | Taille max (`'5M'`, `'500K'`…) |
| `allow(string\|array)` | Ajouter des MIME autorisés |
| `deny(string\|array)` | Ajouter des MIME interdits |
| `allowOnly(string\|array)` | Remplacer la liste autorisée |
| `checkMime(bool)` | Activer/désactiver la vérification MIME |

### Resize & crop

| Méthode | Description |
|---------|-------------|
| `resize(int $w, int $h, ResizeMode)` | Redimensionnement |
| `noEnlarging(bool)` | Interdit l'agrandissement |
| `noShrinking(bool)` | Interdit la réduction |
| `cropAnchor(CropAnchor)` | Ancrage du crop en mode Fill |
| `crop(int $x, int $y, int $w, int $h)` | Crop manuel post-resize |
| `preCrop(int\|string\|array)` | Rognage des bords pré-resize |

### Conversion & qualité

| Méthode | Description |
|---------|-------------|
| `convertTo(ImageFormat)` | Format de sortie |
| `flattenBackground(string\|array)` | Fond pour conversion sans alpha (JPEG, BMP) |
| `jpegQuality(int)` | Qualité JPEG (1–100) |
| `jpegMaxSize(int\|string)` | Qualité JPEG auto pour taille cible |
| `webpQuality(int)` | Qualité WebP (1–100) |
| `pngCompression(?int)` | Compression PNG (1–9) |
| `interlace(bool)` | JPEG progressif / GIF entrelacé |

### Transformations

| Méthode | Description |
|---------|-------------|
| `rotate(int)` | Rotation (multiple de 90°) |
| `autoRotate(bool)` | Auto-rotation EXIF (défaut : `true`) |
| `flip(?FlipDirection)` | Flip horizontal / vertical / les deux |

### Corrections

| Méthode | Description |
|---------|-------------|
| `brightness(int)` | Luminosité (-127 à +127) |
| `contrast(int)` | Contraste (-127 à +127) |
| `opacity(int)` | Opacité (0 à 100) |
| `greyscale(bool)` | Niveaux de gris |
| `negative(bool)` | Négatif |

### Accesseurs source

| Méthode | Retour |
|---------|--------|
| `isUploaded()` | `bool` |
| `isProcessed()` | `bool` |
| `getError()` | `string` |
| `getSrcName()` | Nom du fichier source |
| `getSrcMime()` | Type MIME détecté |
| `getSrcSize()` | Taille en octets |
| `getSrcWidth()` | Largeur en pixels |
| `getSrcHeight()` | Hauteur en pixels |
| `isImage()` | `bool` |

---

*Claude\\Upload\\Uploader — PHP 8.4/8.5 · Compatible GD · Licence MIT*