<?php
// Serves a clean Markdown representation of a page for AI agents/crawlers
// that send `Accept: text/markdown` (see .htaccess), so they get plain
// text instead of having to parse the full HTML/CSS/JS layout. Human
// visitors never hit this file directly — the rewrite only fires when
// the request's Accept header asks for markdown.

$page = isset($_GET['page']) ? $_GET['page'] : '';

if (!preg_match('/^[a-z0-9-]+$/', $page)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

$file = __DIR__ . '/' . $page . '.html';
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

$html = file_get_contents($file);

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

function md_text($str) {
    return trim(preg_replace('/\s+/', ' ', $str));
}

function md_walk($node, $baseUrl) {
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            // Collapse the source HTML's indentation whitespace to single
            // spaces so it doesn't leak through as spurious blank lines.
            $out .= preg_replace('/\s+/', ' ', $child->nodeValue);
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }

        // Decorative/accessibility-hidden nodes (e.g. the duplicate half of
        // a CSS marquee loop) carry nothing a text-only reader needs.
        if ($child->getAttribute('aria-hidden') === 'true') {
            continue;
        }

        $classList = preg_split('/\s+/', trim($child->getAttribute('class')));

        // The trust-badge marquee repeats its items several times over so
        // the CSS scroll loop reads as seamless — dedupe to one clean line.
        if (in_array('marquee', $classList, true)) {
            $items = [];
            foreach ($child->getElementsByTagName('span') as $span) {
                $spanClasses = preg_split('/\s+/', trim($span->getAttribute('class')));
                if (!in_array('it', $spanClasses, true)) continue;
                $text = md_text($span->textContent);
                if ($text !== '' && !in_array($text, $items, true)) $items[] = $text;
            }
            if ($items) $out .= "\n\n" . implode(' · ', $items) . "\n\n";
            continue;
        }

        $tag = strtolower($child->tagName);
        switch ($tag) {
            case 'script':
            case 'style':
            case 'noscript':
            case 'svg':
            case 'form':
                break;
            case 'h1':
                $out .= "\n\n# " . md_text(md_walk($child, $baseUrl)) . "\n\n";
                break;
            case 'h2':
                $out .= "\n\n## " . md_text(md_walk($child, $baseUrl)) . "\n\n";
                break;
            case 'h3':
                $out .= "\n\n### " . md_text(md_walk($child, $baseUrl)) . "\n\n";
                break;
            case 'h4':
            case 'h5':
            case 'h6':
                $out .= "\n\n#### " . md_text(md_walk($child, $baseUrl)) . "\n\n";
                break;
            case 'p':
            case 'div':
            case 'section':
            case 'article':
                $inner = md_walk($child, $baseUrl);
                if (trim($inner) !== '') {
                    $out .= "\n\n" . trim($inner) . "\n\n";
                }
                break;
            case 'ul':
            case 'ol':
                foreach ($child->childNodes as $li) {
                    if ($li->nodeType === XML_ELEMENT_NODE && strtolower($li->tagName) === 'li') {
                        $out .= "- " . md_text(md_walk($li, $baseUrl)) . "\n";
                    }
                }
                $out .= "\n";
                break;
            case 'a':
                $href = $child->getAttribute('href');
                if ($href !== '' && strpos($href, 'tel:') !== 0 && strpos($href, 'mailto:') !== 0
                    && strpos($href, 'http://') !== 0 && strpos($href, 'https://') !== 0) {
                    $href = rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
                }
                $label = md_text($child->textContent);
                $out .= $label !== '' ? '[' . $label . '](' . $href . ')' : '';
                break;
            case 'strong':
            case 'b':
                $out .= '**' . md_text($child->textContent) . '**';
                break;
            case 'em':
            case 'i':
                $out .= '_' . md_text($child->textContent) . '_';
                break;
            case 'br':
                $out .= "\n";
                break;
            default:
                $out .= md_walk($child, $baseUrl);
        }
    }
    return $out;
}

$titleNode = $xpath->query('//title')->item(0);
$title = $titleNode ? md_text($titleNode->textContent) : $page;

$descNode = $xpath->query('//meta[@name="description"]')->item(0);
$description = $descNode ? md_text($descNode->getAttribute('content')) : '';

$mainNode = $xpath->query('//main')->item(0);
if (!$mainNode) {
    $mainNode = $xpath->query('//body')->item(0);
}

$baseUrl = 'https://wastemates.com.au';
$body = $mainNode ? md_walk($mainNode, $baseUrl) : '';

// Collapse any run of blank/whitespace-only lines to a single blank line.
$lines = array_map('rtrim', explode("\n", $body));
$cleaned = [];
$blank = true;
foreach ($lines as $line) {
    if (trim($line) === '') {
        if (!$blank) $cleaned[] = '';
        $blank = true;
    } else {
        $cleaned[] = $line;
        $blank = false;
    }
}
$body = trim(implode("\n", $cleaned));

$canonical = $page === 'index' ? $baseUrl . '/' : $baseUrl . '/' . $page . '.html';

header('Content-Type: text/markdown; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Vary: Accept');

echo "# {$title}\n\n";
if ($description !== '') {
    echo "> {$description}\n\n";
}
echo $body . "\n\n";
echo "---\n\nSource: {$canonical}\n";
