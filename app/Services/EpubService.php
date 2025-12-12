<?php

namespace App\Services;

use ZipArchive;
use DOMDocument;
use DOMXPath;

class EpubService
{
    protected string $baseDir;

    public function __construct()
    {
        $this->baseDir = public_path('books'); // public/books/{id}.epub
    }

    protected function epubPath(int $bookId): string
    {
        return "{$this->baseDir}/{$bookId}.epub";
    }

    /**
     * Open zip and return ZipArchive instance (throws if fails)
     */
    protected function openZip(int $bookId): ZipArchive
    {
        $zip = new ZipArchive();
        $p = $this->epubPath($bookId);
        if ($zip->open($p) !== true) {
            throw new \Exception("Cannot open epub: {$p}");
        }
        return $zip;
    }

    /**
     * Read container.xml and return OPF path (as-is from full-path)
     */
    public function getOpfPath(int $bookId): string
    {
        $zip = $this->openZip($bookId);
        $container = $zip->getFromName('META-INF/container.xml');
        $zip->close();

        if (!$container) throw new \Exception('container.xml not found');

        $doc = new DOMDocument();
        $doc->loadXML($container);
        $rootfile = $doc->getElementsByTagName('rootfile')->item(0);
        if (!$rootfile) throw new \Exception('rootfile not found in container.xml');

        return $rootfile->getAttribute('full-path'); // e.g. "content.opf" or "OPS/content.opf"
    }

    /**
     * Return array of all files inside zip (for fallback matching)
     */
    protected function listZipFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[] = $zip->getNameIndex($i);
        }
        return $files;
    }

    /**
     * Parse OPF and build manifest (id => fullPath) and spine (ordered list of fullPaths)
     * - uses OPF's dirname as base for relative hrefs
     * - attempts to normalize paths to match zip entries (see findRealPath)
     */
    protected function parseOpfAndBuildMaps(ZipArchive $zip): array
    {
        $opfPath = $this->getOpfPathFromZip($zip);
        $opfDir = trim(dirname($opfPath), './');
        if ($opfDir === '.') $opfDir = '';

        $opfXml = $zip->getFromName($opfPath);
        if ($opfXml === false) throw new \Exception("OPF not found in zip: {$opfPath}");

        $doc = new DOMDocument();
        $doc->loadXML($opfXml);

        $xpath = new DOMXPath($doc);
        // register opf namespace (very important)
        $xpath->registerNamespace('opf', 'http://www.idpf.org/2007/opf');

        $manifest = [];
        foreach ($xpath->query('//opf:manifest/opf:item') as $item) {
            $id = $item->getAttribute('id');
            $href = $item->getAttribute('href');
            // make OPF-relative full path (do not assume it's in same folder)
            $full = ($opfDir !== '') ? ($opfDir . '/' . $href) : $href;
            $manifest[$id] = $full;
        }

        $spine = [];
        foreach ($xpath->query('//opf:spine/opf:itemref') as $itemref) {
            $idref = $itemref->getAttribute('idref');
            if (isset($manifest[$idref])) $spine[] = $manifest[$idref];
        }

        return ['opfPath' => $opfPath, 'manifest' => $manifest, 'spine' => $spine];
    }

    /**
     * Helper to get opfPath using already opened zip (avoid reopening)
     */
    protected function getOpfPathFromZip(ZipArchive $zip): string
    {
        $container = $zip->getFromName('META-INF/container.xml');
        if (!$container) throw new \Exception('container.xml not found');
        $doc = new DOMDocument();
        $doc->loadXML($container);
        $rootfile = $doc->getElementsByTagName('rootfile')->item(0);
        return $rootfile->getAttribute('full-path');
    }

    /**
     * Try to find the real path inside zip for a given OPF-relative path
     * - exact match
     * - try without "./"
     * - try basename match (i.e. pick file with same filename anywhere)
     * - try prefixed with possible directories (OPS/, OEBPS/, etc) by basename matching priority
     */
    protected function findRealPath(string $href, array $zipFiles): ?string
    {
        $href = ltrim($href, './');

        // exact
        if (in_array($href, $zipFiles, true)) return $href;

        // try windows slashes -> normalize to forward
        $hrefNorm = str_replace('\\', '/', $href);
        if (in_array($hrefNorm, $zipFiles, true)) return $hrefNorm;

        // basename match (prefer deeper matches by shortest path length)
        $basename = basename($hrefNorm);
        // try exact basename match where path contains basename at end
        $candidates = [];
        foreach ($zipFiles as $f) {
            if (basename($f) === $basename) $candidates[] = $f;
        }
        if (!empty($candidates)) {
            // prefer candidate that contains the original directory fragment (if any)
            usort($candidates, function($a, $b) use ($hrefNorm) {
                return strlen($a) - strlen($b); // prefer shorter path (root) or you may change heuristics
            });
            return $candidates[0];
        }

        return null;
    }

    public function getChapterHtml(int $bookId, int $chapterIndex): array
    {
        $zip = $this->openZip($bookId);
        $zipFiles = $this->listZipFiles($zip);

        $maps = $this->parseOpfAndBuildMaps($zip);
        $manifest = $maps['manifest'];
        $spine = $maps['spine'];

        if (!isset($spine[$chapterIndex])) {
            $zip->close();
            throw new \Exception('chapter missing');
        }

        $href = $spine[$chapterIndex];

        $realPath = $this->findRealPath($href, $zipFiles);
        if ($realPath === null) {
            $zip->close();
            throw new \Exception("chapter file not found in zip: {$href}");
        }

        $raw = $zip->getFromName($realPath);
        if ($raw === false) {
            $zip->close();
            throw new \Exception("failed to read chapter content: {$realPath}");
        }

        libxml_use_internal_errors(true);

        // HTML fragment 로드
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $raw, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        // helper: ZIP 내부 경로 그대로 API 경로 생성
        $assetUrl = fn(string $assetPath) => url("/api/books/{$bookId}/asset?path=" . rawurlencode($assetPath));

        // 1) <img src="">
        foreach ($xpath->query('//img[@src]') as $img) {
            $src = $img->getAttribute('src');
            if (!preg_match('#^https?://#i', $src)) {
                $real = $this->findRealPath($src, $zipFiles);
                if ($real !== null) $img->setAttribute('src', $assetUrl($real));
            }
        }

        // 2) <link rel="stylesheet" href="">
        foreach ($xpath->query('//link[@rel="stylesheet" and @href]') as $link) {
            $href = $link->getAttribute('href');
            if (!preg_match('#^https?://#i', $href)) {
                $real = $this->findRealPath($href, $zipFiles);
                if ($real !== null) $link->setAttribute('href', $assetUrl($real));
            }
        }

        // 3) SVG/xlink 처리: 각 <svg> 태그만 별도로 XML로 로드
        foreach ($xpath->query('//svg') as $svgNode) {
            $svgXml = $dom->saveHTML($svgNode);
            $svgDom = new \DOMDocument();
            $svgDom->loadXML($svgXml);
            $svgXpath = new \DOMXPath($svgDom);
            $svgXpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

            foreach ($svgXpath->query('//*[@xlink:href]') as $node) {
                $val = $node->getAttribute('xlink:href');
                if (!preg_match('#^https?://#i', $val)) {
                    $real = $this->findRealPath($val, $zipFiles);
                    if ($real !== null) $node->setAttribute('xlink:href', $assetUrl($real));
                }
            }

            // 수정된 svg를 원래 dom에 반영
            $newSvg = $dom->importNode($svgDom->documentElement, true);
            $svgNode->parentNode->replaceChild($newSvg, $svgNode);
        }

        // DOM -> HTML fragment로 변환
        $innerHtml = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $innerHtml .= $dom->saveHTML($child);
        }

        $zip->close();
        libxml_clear_errors();

        return [
            'html' => $innerHtml,
            'totalChapters' => count($spine),
            'chapterIndex' => $chapterIndex,
        ];
    }



    /**
     * Asset endpoint: read file binary and return with proper mime
     */
    public function getAssetBinary(int $bookId, string $path)
    {
        $zip = $this->openZip($bookId);
        $zipFiles = $this->listZipFiles($zip);

        $real = $this->findRealPath($path, $zipFiles);
        if ($real === null) {
            $zip->close();
            abort(404);
        }

        $data = $zip->getFromName($real);
        $zip->close();
        if ($data === false) abort(404);

        // guess mime
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if (in_array($ext, ['jpg','jpeg'])) $mime = 'image/jpeg';
        elseif ($ext === 'png') $mime = 'image/png';
        elseif ($ext === 'gif') $mime = 'image/gif';
        elseif ($ext === 'svg') $mime = 'image/svg+xml';
        elseif ($ext === 'css') $mime = 'text/css';
        elseif ($ext === 'ttf') $mime = 'font/ttf';
        elseif ($ext === 'woff') $mime = 'font/woff';
        elseif ($ext === 'woff2') $mime = 'font/woff2';

        return response($data)->header('Content-Type', $mime);
    }
}
