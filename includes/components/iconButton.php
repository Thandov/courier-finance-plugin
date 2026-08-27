<?php
if (!defined('ABSPATH')) {
    exit;
}

class KIT_Icon
{
    public static function svg(string $name, int $size = 16, string $extraClasses = ''): string
    {
        $w = max(12, $size);
        $h = max(12, $size);
        $classAttr = $extraClasses ? ' class="' . esc_attr($extraClasses) . '"' : '';
        switch ($name) {
            case 'download':
                $paths = '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/>';
                break;
            case 'view':
            case 'eye':
                $paths = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>';
                break;
            case 'trash':
            case 'delete':
                $paths = '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>';
                break;
            case 'edit':
                $paths = '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 113 3L7 19l-4 1 1-4 12.5-12.5z"/>';
                break;
            case 'merge':
                $paths = '<path d="M8 6v4"/><path d="M16 6v4"/><path d="M8 18v-4"/><path d="M16 18v-4"/><path d="M8 10h8"/><path d="M8 14h8"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="18" r="2"/><circle cx="16" cy="18" r="2"/>';
                break;
            case 'plus':
                $paths = '<path d="M12 5v14"/><path d="M5 12h14"/>';
                break;
            default:
                $paths = '<circle cx="12" cy="12" r="9"/>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . intval($w) . '" height="' . intval($h) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . $classAttr . '>' . $paths . '</svg>';
    }

    public static function buttonClasses(string $variant = 'gray', string $size = 'sm'): string
    {
        $base = 'inline-flex items-center justify-center rounded-full font-medium border shadow-sm focus:outline-none';
        $sizes = [
            'sm' => ' px-2.5 py-1.5 text-[13px]',
            'md' => ' px-3 py-2 text-sm',
            'lg' => ' px-4 py-2.5 text-sm'
        ];
        $palette = [
            'gray' => ' bg-gray-50 text-gray-700 hover:bg-gray-100 border-gray-200',
            'blue' => ' bg-blue-50 text-blue-700 hover:bg-blue-100 border-blue-200',
            'green' => ' bg-green-50 text-green-700 hover:bg-green-100 border-green-200',
            'red' => ' bg-red-50 text-red-700 hover:bg-red-100 border-red-200'
        ];
        $sz = $sizes[$size] ?? $sizes['sm'];
        $pal = $palette[$variant] ?? $palette['gray'];
        return trim($base . $sz . $pal);
    }
}


