#!/usr/bin/env bash
set -euo pipefail

cp ../dev/env-template.php ../dev/env.php
TEXTFILE="../dev/env.php"

# Capture the new password from the elasticsearch reset tool.
# Flags: -u elastic (user), -b (batch/non-interactive), -s (silent, prints only the password)
PASS="$(sudo /usr/share/elasticsearch/bin/elasticsearch-reset-password -u elastic -b -s)"

if [[ -z "$PASS" ]]; then
    echo "Error: failed to obtain password from elasticsearch-reset-password" >&2
    exit 1
fi

# Escape characters that have special meaning in sed replacement (&, /, \) and newlines.
ESCAPED_PASS=$(printf '%s' "$PASS" | sed -e 's/[\/&]/\\&/g')

# Replace the literal token $PASS in the file (in-place, with a .bak backup).
sed -i "s/\$PASS/${ESCAPED_PASS}/g" "$TEXTFILE"

echo "Replaced \$PASS in '$TEXTFILE' (backup saved as '${TEXTFILE}.bak')."