# Download Missing Attachments

This is for those situations where someone delivers you a database dump 
from a WordPress site, but you don't have access to the images and other 
user uploaded files. 

This plugin adds a [WP CLI](http://wp-cli.org/) command to download any 
files which are missing for attachments.

## Examples

```bash
wp remote-attachments get --remote-url-base=http://www.example.com/wp-content/uploads/ --generate-thumbs
```

```bash
wp remote-attachments get --remote-url-base=http://www.example.com/files/
```
