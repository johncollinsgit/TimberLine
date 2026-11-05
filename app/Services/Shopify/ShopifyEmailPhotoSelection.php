<?php

namespace App\Services\Shopify;

use Illuminate\Validation\ValidationException;

class ShopifyEmailPhotoSelection
{
    /** @param array<string,mixed> $store
     * @return array{id:string,url:string,alt:string}
     */
    public function resolve(array $store, mixed $id): array
    {
        $input = validator(['id' => $id], [
            'id' => ['required', 'string', 'max:100', 'regex:#^gid://shopify/MediaImage/[1-9][0-9]*$#'],
        ])->validate();

        $result = (new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']))->query(
            'query EmailPhotoSelection($id:ID!){node(id:$id){... on MediaImage{id alt image{url}}}}',
            ['id' => $input['id']],
        );
        $photo = $result['node'] ?? null;
        $url = is_array($photo) ? ($photo['image']['url'] ?? null) : null;
        if (($photo['id'] ?? null) !== $input['id'] || ! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['id' => 'That photo is unavailable in this store. Choose another photo.']);
        }

        return ['id' => $photo['id'], 'url' => $url, 'alt' => (string) ($photo['alt'] ?? '')];
    }
}
