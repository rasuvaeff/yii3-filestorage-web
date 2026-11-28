<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Url\HmacUrlSigner;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Rasuvaeff\Yii3Filestorage\Url\UrlSignerInterface;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Rasuvaeff\Yii3FilestorageWeb\Url\HmacProxyUrlGenerator;
use Rasuvaeff\Yii3FilestorageWeb\Http\FileResponseFactory;
use Yiisoft\Router\CurrentRoute;

/** @var array $params */

// This package owns the HTTP half: the signer, its key ring, the proxy URL
// generator core leaves unbound, and the download action. Core binds the
// facade; `-db` the metadata; a store backend the bytes. One key, one vendor
// package — two claiming the same one is a `yiisoft/config` `Duplicate key`
// error by design.
//
// ScopedFileResolverInterface comes from `-db`. Without it this package cannot
// resolve a signed download at all, which is deliberate: the alternative is
// looking a file up by id with no scope, and that reads any file whose id
// leaks.
return [
    // Straight construction, no branching: the key ring validates ids and
    // secret lengths itself and says exactly what to do about a bad one.
    SigningKeyRing::class => static fn (): SigningKeyRing => new SigningKeyRing(
        activeKeyId: (string) $params['rasuvaeff/yii3-filestorage-web']['signingKeys']['active'],
        keys: $params['rasuvaeff/yii3-filestorage-web']['signingKeys']['keys'],
    ),

    UrlSignerInterface::class => static fn (
        ClockInterface $clock,
        SigningKeyRing $keys,
    ): UrlSignerInterface => new HmacUrlSigner(clock: $clock, keys: $keys),

    // Bound by class too, so a background job can ask for the concrete
    // generator and call forScope() — autowiring it instead would silently
    // use the default route and no scope provider.
    HmacProxyUrlGenerator::class => static fn (
        UrlSignerInterface $signer,
        ?FileScopeProviderInterface $scopes = null,
    ): HmacProxyUrlGenerator => new HmacProxyUrlGenerator(
        signer: $signer,
        route: (string) $params['rasuvaeff/yii3-filestorage-web']['route'],
        scopes: $scopes,
    ),

    // Core declares this optional and leaves it unbound; installing this
    // package is what gives a private store a URL at all.
    ProxyUrlGeneratorInterface::class => static fn (
        HmacProxyUrlGenerator $generator,
    ): ProxyUrlGeneratorInterface => $generator,

    FileResponseFactory::class => static fn (
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
    ): FileResponseFactory => new FileResponseFactory(responses: $responses, streams: $streams),

    // `currentRoute` is the optional-dep half of the token lookup: nullable
    // with a default, so a container without yiisoft/router resolves it to
    // null (yiisoft/di gives optional parameters their default when the class
    // cannot be resolved) and the action reads the token from the request
    // attribute alone. In a yiisoft/router application CurrentRoute is the
    // same singleton the Router middleware populated.
    //
    // `derivatives` follows the same optional-dep rule: `-images` binds
    // DerivativeResolverInterface, and without it every variant token is a 404.
    // Not passing it here made that 404 the only behaviour even with `-images`
    // installed.
    FileDownloadAction::class => static function (
        UrlSignerInterface $signer,
        ScopedFileResolverInterface $files,
        StoreRegistry $stores,
        DeliveryPolicyRegistry $deliveryPolicies,
        FileResponseFactory $downloads,
        ResponseFactoryInterface $responses,
        ?CurrentRoute $currentRoute = null,
        ?DerivativeResolverInterface $derivatives = null,
    ) use ($params): FileDownloadAction {
        // The active-type list is core's (`activeMediaTypes` in its params)
        // and reaches the action through the delivery registry: one instance
        // for the proxy, presigned URLs and `check`. This package's own key is
        // gone (0.7.0); an application still setting it would have the types
        // it named served inline without a word, so it is refused instead.
        if (\array_key_exists('extraActiveMediaTypes', $params['rasuvaeff/yii3-filestorage-web'])) {
            throw new InvalidConfigException(
                'The "rasuvaeff/yii3-filestorage-web" param "extraActiveMediaTypes" was removed in 0.7.0: move its '
                . 'types to "rasuvaeff/yii3-filestorage" => "activeMediaTypes" => "extra"',
            );
        }

        return new FileDownloadAction(
            signer: $signer,
            files: $files,
            stores: $stores,
            deliveryPolicies: $deliveryPolicies,
            downloads: $downloads,
            responses: $responses,
            cacheControl: (string) $params['rasuvaeff/yii3-filestorage-web']['cacheControl'],
            tokenAttribute: (string) $params['rasuvaeff/yii3-filestorage-web']['tokenAttribute'],
            currentRoute: $currentRoute,
            derivatives: $derivatives,
        );
    },
];
