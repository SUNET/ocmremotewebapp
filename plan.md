
# Nextcloud app to retrieve and display OCM remote webapp shares.

- It will provide a CloudFederationProvider to accept the shares from the cloud_federation_api RequestHandlerController. It needs to be registered.
- It will listen to the LocalOCMDiscoveryEvent event and inject its data (capabilities, resource type) to the discovery payload. Listener, registration.
- It will have a db table available to store the accepted webapp shares. Migration + webappShare entity + WebappShareMapper.
- It will be able to display controls to allow users access to the available webapp shares. Template (+ way to inject it in pages?)
- It will be able to retrieve and provide access to the webapps requested byt the users. Page Controller to hold the iframe.
- Depending on the webapp, it will be able to display them:
    - in an iframe
    - in a popup
    - in a redirect
