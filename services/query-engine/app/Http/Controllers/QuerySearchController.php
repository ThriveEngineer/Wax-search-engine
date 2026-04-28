<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuerySearchController extends Controller
{
    public function get_page_connections(Request $request)
    {
        $url = $request->input('url');
        if (!$url) {
            return response()->json(['error' => 'URL is required'], 400);
        }

        error_log('URL: ' . $url);

        // Fetch page outlinks
        $outlinksData = DB::connection('mongodb')
            ->table('outlinks')
            ->where('id', $url)
            ->first();

        // if (!$outlinksData) {
        //     return response()->json([
        //         'status' => 'error',
        //         'message' => 'URL not found in outlinks database'
        //     ], 404);
        // }

        $outlinks = $outlinksData->links ?? [];

        // Fetch metadata for each outlink
        $enrichedOutlinks = [];
        if (count($outlinks) > 0) {
            $metadataCollection = DB::connection('mongodb')
                ->table('metadata')
                ->whereIn('_id', $outlinks)
                ->get();

            $metadataMap = [];
            foreach ($metadataCollection as $metadata) {
                $metadataMap[$metadata->id] = $metadata;
            }

            foreach ($outlinks as $link) {
                if (isset($metadataMap[$link])) {
                    $enrichedOutlinks[] = [
                        'url' => $link,
                        'title' => $metadataMap[$link]->title ?? 'Page Not Indexed',
                    ];
                } else {
                    $enrichedOutlinks[] = [
                        'url' => $link,
                        'title' => 'Page Not Indexed'
                    ];
                }
            }
        }

        // Fetch page backlinks
        $backlinksData = DB::connection('mongodb')
            ->table('backlinks')
            ->where('id', $url)
            ->first();

        // if (!$backlinksData) {
        //     return response()->json([
        //         'status' => 'error',
        //         'message' => 'URL not found in backlinks database'
        //     ], 404);
        // }

        $backlinks = $backlinksData->links ?? [];

        // Fetch metadata for each backlink
        $enrichedBacklinks = [];
        if (count($backlinks) > 0) {
            $metadataCollection = DB::connection('mongodb')
                ->table('metadata')
                ->whereIn('_id', $backlinks)
                ->get();

            $metadataMap = [];
            foreach ($metadataCollection as $metadata) {
                $metadataMap[$metadata->id] = $metadata;
            }

            foreach ($backlinks as $link) {
                if (isset($metadataMap[$link])) {
                    $enrichedBacklinks[] = [
                        'url' => $link,
                        'title' => $metadataMap[$link]->title ?? 'Page Not Indexed',
                    ];
                } else {
                    $enrichedBacklinks[] = [
                        'url' => $link,
                        'title' => 'Page Not Indexed'
                    ];
                }
            }
        }

        // Get url metadata
        $urlMetadata = DB::connection('mongodb')
            ->table('metadata')
            ->where('_id', $url)
            ->first();

        return view('page-connections', [
            'url' => $url,
            'title' => $urlMetadata->title ?? 'Page Not Indexed',
            'outlinks' => $enrichedOutlinks,
            'backlinks' => $enrichedBacklinks,
        ]);
    }
    public function getTopImages($query, $page = 1, $perPage = 5)
    {
        // Split the query string
        $query = str_replace('+', ' ', $query);
        $words = explode(' ', strtolower($query));

        // Use a count aggregation to get total results more efficiently
        $countPipeline = [
            ['$match' => ['word' => ['$in' => $words]]],
            ['$group' => ['_id' => '$url']],
            ['$count' => 'total']
        ];

        $countResult = DB::connection('mongodb')
            ->table('word_images')
            ->raw(fn($collection) => $collection->aggregate($countPipeline)->toArray());

        $totalResults = isset($countResult[0]) ? $countResult[0]['total'] : 0;

        // Aggregation
        $paginationPipeline = [
            ['$match' => ['word' => ['$in' => $words]]],
            [
                '$group' => [
                    '_id' => '$url',
                    'cumWeight' => ['$sum' => '$weight'],
                    'matchedWords' => ['$addToSet' => '$word'],
                    'matchCount' => ['$sum' => 1]
                ]
            ],
            ['$sort' => ['matchCount' => -1, 'cumWeight' => -1]],
            ['$skip' => ($page - 1) * $perPage],
            ['$limit' => $perPage]
        ];

        // Get paginated results
        $paginatedResults = DB::connection('mongodb')
            ->table('word_images')
            ->raw(function ($collection) use ($paginationPipeline) {
                // Use a cursor to iterate through the results
                $cursor = $collection->aggregate($paginationPipeline, ['cursor' => ['batchSize' => 20]]);
                $results = [];
                foreach ($cursor as $document) {
                    $results[] = $document;
                }
                return $results;
            });

        // Populate the metadata for each URL in the paginated results
        $urls = array_map(fn($result) => $result['_id'], $paginatedResults);

        // Fetch image data
        $imagesData = DB::connection('mongodb')->table('images')
            ->whereIn('_id', $urls)
            ->get();

        // First, reindex the metadata by _id for fast lookup
        $imageDataByUrl = [];
        foreach ($imagesData as $data) {
            $imageDataByUrl[$data->id] = $data;
        }

        // Get all the page urls
        $pageUrls = [];
        foreach ($imageDataByUrl as $result) {
            $pageUrls[] = $result->page_url ?? '';
        }

        // Fetch all pages metadata
        $pageMetadataList = DB::connection('mongodb')->table('metadata')
            ->whereIn('_id', $pageUrls)
            ->get();

        // Reindex page metadata by _id
        $pageMetadataByUrl = [];
        foreach ($pageMetadataList as $meta) {
            $pageMetadataByUrl[$meta->id] = $meta;
        }

        // Merge image data into each paginated result
        foreach ($paginatedResults as &$result) {
            $imageData = $imageDataByUrl[$result['_id']] ?? null;
            $result['alt'] = $imageData->alt ?? '';
            $result['filname'] = $imageData->filename ?? '';
            $result['page_url'] = $imageData->page_url ?? '';
            $pageMetadata = $pageMetadataByUrl[$result['page_url']] ?? null;
            $result['page_title'] = $pageMetadata->title ?? '';
            // Shorten the summary text to 300 characters
            $result['page_text'] = '';
            $length = 100;
            if (isset($pageMetadata->summary_text)) {
                $result['page_text'] = strlen($pageMetadata->summary_text) > $length
                    ? substr($pageMetadata->summary_text, 0, $length) . '...'
                    : $pageMetadata->summary_text;
            }
        }

        return [$paginatedResults, $totalResults];
    }

    public function stats()
    {
        $results = DB::connection('mongodb')->table('metadata')->count();

        return response()->json([
            'status' => 'up',
            'pages' => $results,
        ]);
    }

    public function searx(Request $request)
    {
        $query = trim((string) $request->input('processedQuery', $request->input('q', '')));
        $page = max(1, (int) $request->input('pageno', $request->input('page', 1)));
        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 50));

        $categoriesRaw = strtolower((string) $request->input('categories', 'general'));
        $categories = array_values(array_filter(array_map('trim', explode(',', $categoriesRaw))));
        $category = $categories[0] ?? 'general';

        if ($query === '') {
            return response()->json([
                'query' => '',
                'category' => $category,
                'number_of_results' => 0,
                'results' => [],
            ])->withHeaders($this->corsHeaders());
        }

        if ($category === 'images') {
            [$imageResults, $totalResults] = $this->getTopImages($query, $page, $perPage);

            $results = [];
            foreach ($imageResults as $result) {
                $results[] = [
                    'url' => $result['page_url'] ?? '',
                    'title' => $result['page_title'] ?? ($result['alt'] ?? 'Image'),
                    'content' => $result['page_text'] ?? '',
                    'img_src' => $result['_id'] ?? '',
                    'thumbnail_src' => $result['_id'] ?? '',
                    'category' => 'images',
                    'engine' => 'wax-search',
                ];
            }

            return response()->json([
                'query' => $query,
                'category' => 'images',
                'number_of_results' => $totalResults,
                'results' => $results,
            ])->withHeaders($this->corsHeaders());
        }

        if ($category === 'videos') {
            return response()->json([
                'query' => $query,
                'category' => 'videos',
                'number_of_results' => 0,
                'results' => [],
            ])->withHeaders($this->corsHeaders());
        }

        $normalizedQuery = str_replace('+', ' ', $query);
        $words = array_values(array_filter(explode(' ', strtolower($normalizedQuery))));
        if (empty($words)) {
            return response()->json([
                'query' => $query,
                'category' => 'general',
                'number_of_results' => 0,
                'results' => [],
            ])->withHeaders($this->corsHeaders());
        }

        $countPipeline = [
            ['$match' => ['word' => ['$in' => $words]]],
            ['$group' => ['_id' => '$url']],
            ['$count' => 'total']
        ];

        $countResult = DB::connection('mongodb')
            ->table('words')
            ->raw(fn($collection) => $collection->aggregate($countPipeline)->toArray());

        $totalResults = isset($countResult[0]) ? $countResult[0]['total'] : 0;

        $paginationPipeline = [
            ['$match' => ['word' => ['$in' => $words]]],
            [
                '$group' => [
                    '_id' => '$url',
                    'cumWeight' => ['$sum' => '$weight'],
                    'matchedWords' => ['$addToSet' => '$word'],
                    'matchCount' => ['$sum' => 1]
                ]
            ],
            ['$sort' => ['matchCount' => -1, 'cumWeight' => -1]],
            ['$skip' => ($page - 1) * $perPage],
            ['$limit' => $perPage]
        ];

        $paginatedResults = DB::connection('mongodb')
            ->table('words')
            ->raw(function ($collection) use ($paginationPipeline) {
                $cursor = $collection->aggregate($paginationPipeline, ['cursor' => ['batchSize' => 20]]);
                $results = [];
                foreach ($cursor as $document) {
                    $results[] = $document;
                }
                return $results;
            });

        $urls = array_map(fn($result) => $result['_id'], $paginatedResults);
        $pageRank = DB::connection('mongodb')->table('pagerank')
            ->whereIn('_id', $urls)
            ->get();
        $metadata = DB::connection('mongodb')->table('metadata')
            ->whereIn('_id', $urls)
            ->get();

        $pageRankByUrl = [];
        foreach ($pageRank as $rankRow) {
            $pageRankByUrl[$rankRow->id] = (float) ($rankRow->rank ?? 0);
        }

        $metadataByUrl = [];
        foreach ($metadata as $meta) {
            $metadataByUrl[$meta->id] = $meta;
        }

        foreach ($paginatedResults as &$result) {
            $resultMetadata = $metadataByUrl[$result['_id']] ?? null;
            $result['description'] = $resultMetadata->description ?? '';
            $result['summary_text'] = $resultMetadata->summary_text ?? '';
            $result['title'] = $resultMetadata->title ?? '';
            $result['pagerank'] = $pageRankByUrl[$result['_id']] ?? 0;
            $result['combinedScore'] = (0.6 * $result['cumWeight']) + (0.4 * $result['pagerank']);
        }

        usort($paginatedResults, function ($a, $b) {
            return $b['combinedScore'] <=> $a['combinedScore'];
        });

        $results = [];
        foreach ($paginatedResults as $result) {
            $url = $result['_id'] ?? '';
            $meta = $metadataByUrl[$url] ?? null;
            $host = parse_url($url, PHP_URL_HOST);
            $favicon = $host ? 'https://www.google.com/s2/favicons?sz=64&domain=' . $host : null;

            $snippet = $meta->summary_text ?? ($meta->description ?? '');
            if ($snippet !== '' && strlen($snippet) > 240) {
                $snippet = substr($snippet, 0, 240) . '...';
            }

            $results[] = [
                'url' => $url,
                'title' => $meta->title ?? $url,
                'content' => $snippet,
                'score' => $result['combinedScore'] ?? 0,
                'favicon' => $favicon,
                'category' => 'general',
                'engine' => 'wax-search',
            ];
        }

        return response()->json([
            'query' => $query,
            'category' => 'general',
            'number_of_results' => $totalResults,
            'results' => $results,
        ])->withHeaders($this->corsHeaders());
    }

    public function search(Request $request)
    {
        $hasSuggestions = $request->input('hasSuggestions');
        $originalQuery = $request->input('q');
        $processedQuery = $request->input('processedQuery');
        $query = $processedQuery;
        if (!$query) {
            $query = "";
            return view('search-results', [
                'query' => $query,
                'results' => [],
                'total' => 0,
                'topImages' => [],
                'suggestions' => $hasSuggestions,
                'originalQuery' => $originalQuery,
                'page' => 0,
            ]);
        }

        // Split the query string
        $query = str_replace('+', ' ', $query);
        $words = explode(' ', strtolower($query));

        // Set the number of results per page
        $perPage = 20;
        $page = $request->input('page', 1); // Default page 1

        // Use a count aggregation to get total results more efficiently
        $countPipeline = [
            ['$match' => ['word' => ['$in' => $words]]],
            ['$group' => ['_id' => '$url']],
            ['$count' => 'total']
        ];

        $countResult = DB::connection('mongodb')
            ->table('words')
            ->raw(fn($collection) => $collection->aggregate($countPipeline)->toArray());

        $totalResults = isset($countResult[0]) ? $countResult[0]['total'] : 0;

        // Aggregation
        $paginationPipeline = [
            ['$match' => ['word' => ['$in' => $words]]],
            [
                '$group' => [
                    '_id' => '$url',
                    'cumWeight' => ['$sum' => '$weight'],
                    'matchedWords' => ['$addToSet' => '$word'],
                    'matchCount' => ['$sum' => 1]
                ]
            ],
            ['$sort' => ['matchCount' => -1, 'cumWeight' => -1]],
            ['$skip' => ($page - 1) * $perPage],
            ['$limit' => $perPage]
        ];

        // Get paginated results
        $paginatedResults = DB::connection('mongodb')
            ->table('words')
            ->raw(function ($collection) use ($paginationPipeline) {
                // Use a cursor to iterate through the results
                $cursor = $collection->aggregate($paginationPipeline, ['cursor' => ['batchSize' => 20]]);
                $results = [];
                foreach ($cursor as $document) {
                    $results[] = $document;
                }
                return $results;
            });

        // Populate the metadata for each URL in the paginated results
        $urls = array_map(fn($result) => $result['_id'], $paginatedResults);
        // TODO: Add page rank
        // Fetch page rank of the urls
        $pageRank = DB::connection('mongodb')->table('pagerank')
            ->whereIn('_id', $urls)
            ->get();

        error_log('Page rank: ' . json_encode($pageRank));

        $pageRankByUrl = [];
        foreach ($pageRank as $rankRow) {
            $pageRankByUrl[$rankRow->id] = (float) ($rankRow->rank ?? 0);
        }

        $metadata = DB::connection('mongodb')->table('metadata')
            ->whereIn('_id', $urls)
            ->get();

        // First, reindex the metadata by _id for fast lookup
        $metadataByUrl = [];
        foreach ($metadata as $meta) {
            $metadataByUrl[$meta->id] = $meta;
        }

        // Now, merge metadata into each paginated result
        foreach ($paginatedResults as &$result) {
            $resultMetadata = $metadataByUrl[$result['_id']] ?? null;
            $result['description'] = $resultMetadata->description ?? '';
            $result['last_crawled'] = $resultMetadata->last_crawled ?? '';
            $result['summary_text'] = $resultMetadata->summary_text ?? '';
            $result['title'] = $resultMetadata->title ?? '';

            $result['pagerank'] = $pageRankByUrl[$result['_id']] ?? 0; // Default to 0 if no pagerank found

            // Calculate combined score
            $tfidfWeight = $result['cumWeight'];
            $pageRankWeight = $result['pagerank'];

            // Use 60% TF-IDF and 40% PageRank for the combined score
            $combinedScore = (0.6 * $tfidfWeight) + (0.4 * $pageRankWeight);

            // Add the combined score to the result for sorting purposes
            $result['combinedScore'] = $combinedScore;
        }

        // Sort the results by the combined score in descending order
        usort($paginatedResults, function ($a, $b) {
            return $b['combinedScore'] <=> $a['combinedScore'];
        });

        // Get top 5 images if it's the first page
        $topImages = [];
        if ($page == 1) {
            [$topImages, $unused] = $this->getTopImages($query, $page, 5);
        }

        // Return view for SSR
        return view('search-results', [
            'query' => $query,
            'results' => $paginatedResults,
            'total' => $totalResults,
            'topImages' => $topImages,
            'suggestions' => $hasSuggestions,
            'originalQuery' => $originalQuery,
            'page' => $page,
        ]);
    }

    public function search_images(Request $request)
    {
        $suggestions = $request->input('suggestions');
        $originalQuery = $request->input('q');
        // $query = $request->input('processed_query');
        $query = $originalQuery;
        if (!$query) {
            $query = "";
            return view('search-image-results', [
                'query' => $query,
                'results' => [],
                'total' => 0,
                'topImages' => [],
                'suggestions' => $suggestions,
                'originalQuery' => $originalQuery,
            ]);
        }

        // Split the query string
        $query = str_replace('+', ' ', $query);
        $words = explode(' ', strtolower($query));

        // Set the number of results per page
        $perPage = 20;
        $page = $request->input('page', 1); // Default page 1

        [$paginatedResults, $totalResults] = $this->getTopImages($query, $page, $perPage);

        return view('search-image-results', [
            'query' => $query,
            'results' => $paginatedResults,
            'total' => $totalResults,
            'topImages' => [],
            'suggestions' => $suggestions,
            'originalQuery' => $originalQuery,
        ]);
    }

    public function get_top_ranked_page(Request $request)
    {
        // Get the top ranked page from pagerank
        $results = DB::connection('mongodb')->table('pagerank')
            ->orderBy('rank', 'desc')
            ->limit(1)
            ->get();
        if ($results->count() <= 0) {
            return null;
        }

        // Fetch the page metadata
        $page_metadata = DB::connection('mongodb')->table('metadata')
            ->where('_id', $results[0]->id)
            ->first();
        if (!$page_metadata) {
            return null;
        }

        // Return the page metadata as an array
        return [
            'title' => $page_metadata->title,
            'url' => $page_metadata->id,
            'description' => $page_metadata->description,
            'last_crawled' => $page_metadata->last_crawled,
            'summary_text' => $page_metadata->summary_text,
        ];
    }

    // Make a function to get a random page from the metadata collection
    public function get_random_page(Request $request)
    {
        $results = DB::connection('mongodb')
            ->table('metadata')
            ->raw(function ($collection) {
                return $collection->aggregate([
                    ['$sample' => ['size' => 1]]
                ]);
            });

        $document = $results->toArray();

        if (empty($document)) {
            return null;
        }

        $doc = $document[0];

        // Return the page metadata as an array
        return [
            'title' => $doc['title'],
            'url' => $doc['_id'],
            'description' => $doc['description'],
            'last_crawled' => $doc['last_crawled'],
            'summary_text' => $doc['summary_text'],
        ];
    }

    public function get_dictionary()
    {
        $results = DB::connection('mongodb')
            ->table('dictionary')
            ->pluck('_id'); // ONLY get the word strings

        return response()->json([
            'status' => 'up',
            'dictionary' => $results,
        ]);
    }

    private function corsHeaders()
    {
        return [
            'Access-Control-Allow-Origin' => env('CORS_ALLOWED_ORIGIN', '*'),
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ];
    }



}
