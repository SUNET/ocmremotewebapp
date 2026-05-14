
# Nextcloud app to retrieve and display OCM remote webapp shares.

- It will provide an endpoint to receive webapp shares.
- It will listen to the LocalOCMDiscoveryEvent event and inject its data (capabilities, resource type) to the discovery payload.
- These data will include its endpoint to receive shares.
- It will have a db table available to store the accepted webapp shares.
- It will be able to display controls to allow users access to the available webapp shares.
- It will be able to retrieve and provide access to the webapps requested byt the users.
- Depending on the webapp, it will be able to display them:
    - in an iframe
    - in a popup
    - in a redirect
