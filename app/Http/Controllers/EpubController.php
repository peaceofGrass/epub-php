<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EpubService;

class EpubController extends Controller
{
    protected EpubService $service;

    public function __construct(EpubService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/books/{bookId}/chapter?index=0
     * index is 0-based chapter index
     */
    public function chapter(Request $req, int $bookId)
    {
        $index = (int) $req->query('index', 0);

        try {
            $res = $this->service->getChapterHtml($bookId, $index);
            return response()->json([
                'success' => true,
                'html' => $res['html'],
                'chapterIndex' => $res['chapterIndex'],
                'totalChapters' => $res['totalChapters'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * GET /api/books/{bookId}/asset?path=OPS/images/a.png
     */
    public function asset(Request $req, int $bookId)
    {
        $path = (string) $req->query('path', '');
        if ($path === '') abort(404);
        return $this->service->getAssetBinary($bookId, $path);
    }
}
