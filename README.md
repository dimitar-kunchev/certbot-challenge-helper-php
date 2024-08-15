# Store certbot validation tokens in a Mongo database so a central server can validate them

This script is only a helper. You still need to link it to other parts of your system to get it going

You will need to wrap it with shell scripts or a CLI commands implementation so certbot can call it.

You will also need to handle the .well-known/acme-challenge/ requests in your web server and forward them to the helper

