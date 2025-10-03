<?php

namespace WaterlooBae\UwAdfs\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use WaterlooBae\UwAdfs\Services\ProxyService;
use WaterlooBae\UwAdfs\Services\AdfsService;

class ProxyController extends Controller
{
    protected ProxyService $proxyService;
    protected AdfsService $adfsService;

    public function __construct(ProxyService $proxyService, AdfsService $adfsService)
    {
        $this->proxyService = $proxyService;
        $this->adfsService = $adfsService;
    }

    /**
     * Handle incoming SAML authentication request from client application
     */
    public function sso(Request $request)
    {
        if (!$this->proxyService->isEnabled()) {
            abort(404, 'Proxy mode not enabled');
        }

        try {
            // Parse incoming SAML request
            $samlRequest = $this->parseSamlRequest($request);
            
            // Handle client request and store context
            $clientContext = $this->proxyService->handleClientRequest($samlRequest);
            
            // Forward to upstream ADFS
            $this->proxyService->forwardToUpstream($clientContext);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Proxy SSO failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle SAML response from upstream ADFS (Assertion Consumer Service)
     */
    public function acs(Request $request)
    {
        if (!$this->proxyService->isEnabled()) {
            abort(404, 'Proxy mode not enabled');
        }

        try {
            // Process upstream response
            $proxyResult = $this->proxyService->handleUpstreamResponse();
            
            // Generate response for client
            $responseXml = $this->proxyService->generateClientResponse(
                $proxyResult['client_context'],
                $proxyResult['saml_data']
            );
            
            // Send response to client
            $this->proxyService->sendResponseToClient($responseXml, $proxyResult['client_context']);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Proxy ACS failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle logout request (Single Logout Service)
     */
    public function sls(Request $request)
    {
        if (!$this->proxyService->isEnabled()) {
            abort(404, 'Proxy mode not enabled');
        }

        try {
            // Handle logout - forward to upstream and clean up sessions
            // Implementation depends on specific requirements
            return redirect('/')->with('success', 'Logged out successfully');
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Proxy SLS failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Return proxy metadata for client applications
     */
    public function metadata()
    {
        if (!$this->proxyService->isEnabled()) {
            abort(404, 'Proxy mode not enabled');
        }

        try {
            $metadata = $this->proxyService->getProxyMetadata();
            
            return response($metadata, 200, [
                'Content-Type' => 'application/xml',
                'Cache-Control' => 'public, max-age=3600',
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate proxy metadata',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Proxy status and configuration endpoint
     */
    public function status(Request $request)
    {
        if (!$this->proxyService->isEnabled()) {
            return response()->json([
                'proxy_enabled' => false,
                'message' => 'Proxy mode not enabled'
            ]);
        }

        return response()->json([
            'proxy_enabled' => true,
            'proxy_mode' => $this->proxyService->getMode(),
            'entity_id' => config('app.url') . '/proxy',
            'endpoints' => [
                'sso' => config('app.url') . '/saml/proxy/sso',
                'acs' => config('app.url') . '/saml/proxy/acs',
                'sls' => config('app.url') . '/saml/proxy/sls',
                'metadata' => config('app.url') . '/saml/proxy/metadata',
            ],
            'configuration' => [
                'session_lifetime' => config('uw-adfs.proxy.session_lifetime', 3600),
                'attribute_filtering' => config('uw-adfs.proxy.attribute_filtering', true),
                'sign_assertions' => config('uw-adfs.proxy.sign_assertions', true),
            ]
        ]);
    }

    /**
     * Parse incoming SAML request
     */
    protected function parseSamlRequest(Request $request): array
    {
        $samlRequest = $request->get('SAMLRequest');
        $relayState = $request->get('RelayState');

        if (empty($samlRequest)) {
            throw new \Exception('Missing SAMLRequest parameter');
        }

        // Decode SAML request
        $decodedRequest = base64_decode($samlRequest);
        
        // For HTTP-Redirect binding, the request might be deflated
        if ($request->isMethod('GET')) {
            $decodedRequest = gzinflate($decodedRequest);
        }

        // Parse XML to extract key information
        $xml = new \DOMDocument();
        $xml->loadXML($decodedRequest);

        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

        // Extract issuer
        $issuerNodes = $xpath->query('//saml:Issuer');
        $issuer = $issuerNodes->length > 0 ? $issuerNodes->item(0)->textContent : null;

        // Extract request ID
        $requestNodes = $xpath->query('//samlp:AuthnRequest');
        $requestId = null;
        $acsUrl = null;
        
        if ($requestNodes->length > 0) {
            $requestElement = $requestNodes->item(0);
            if ($requestElement instanceof \DOMElement) {
                $requestId = $requestElement->getAttribute('ID');
                $acsUrl = $requestElement->getAttribute('AssertionConsumerServiceURL');
            }
        }

        if (empty($issuer) || empty($requestId)) {
            throw new \Exception('Invalid SAML request: missing issuer or request ID');
        }

        return [
            'issuer' => $issuer,
            'id' => $requestId,
            'acs_url' => $acsUrl,
            'relay_state' => $relayState,
            'raw_request' => $decodedRequest,
        ];
    }
}