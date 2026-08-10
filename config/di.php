<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Url\HmacUrlSigner;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Rasuvaeff\Yii3Filestorage\Url\UrlSignerInterface;
use Rasuvaeff\Yii3FilestorageWeb\ActiveMediaTypes;
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Rasuvaeff\Yii3FilestorageWeb\Url\HmacProxyUrlGenerator;
use Rasuvaeff\Yii3FilestorageWeb\Http\FileResponseFactory;

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

    ActiveMediaTypes::class => static fn (): ActiveMediaTypes => ActiveMediaTypes::withExtra(
        $params['rasuvaeff/yii3-filestorage-web']['extraActiveMediaTypes'],
    ),

    // Core declares this optional and leaves it unbound; installing this
    // package is what gives a private store a URL at all.
    ProxyUrlGeneratorInterface::class => static fn (
        UrlSignerInterface $signer,
        ?FileScopeProviderInterface $scopes = null,
    ): ProxyUrlGeneratorInterface => new HmacProxyUrlGenerator(
        signer: $signer,
        route: (string) $params['rasuvaeff/yii3-filestorage-web']['route'],
        scopes: $scopes,
    ),

    FileResponseFactory::class => static fn (
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
    ): FileResponseFactory => new FileResponseFactory(responses: $responses, streams: $streams),

    FileDownloadAction::class => static fn (
        UrlSignerInterface $signer,
        ScopedFileResolverInterface $files,
        StoreRegistry $stores,
        DeliveryPolicyRegistry $deliveryPolicies,
        FileResponseFactory $downloads,
        ResponseFactoryInterface $responses,
        ActiveMediaTypes $activeMediaTypes,
    ): FileDownloadAction => new FileDownloadAction(
        signer: $signer,
        files: $files,
        stores: $stores,
        deliveryPolicies: $deliveryPolicies,
        downloads: $downloads,
        responses: $responses,
        activeMediaTypes: $activeMediaTypes,
        cacheControl: (string) $params['rasuvaeff/yii3-filestorage-web']['cacheControl'],
        tokenAttribute: (string) $params['rasuvaeff/yii3-filestorage-web']['tokenAttribute'],
    ),
];
