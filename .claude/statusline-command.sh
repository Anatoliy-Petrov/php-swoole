#!/usr/bin/env bash
# Claude Code status line: usage indicators with progress bars
input=$(cat)

# Build a progress bar: bar <value 0-100> <width>
bar() {
  local pct=$1
  local width=${2:-10}
  local filled=$(( pct * width / 100 ))
  local empty=$(( width - filled ))
  printf '%*s' "$filled" '' | tr ' ' '#'
  printf '%*s' "$empty" '' | tr ' ' '-'
}

# Context window usage
used_pct=$(echo "$input" | jq -r '.context_window.used_percentage // empty')

# Rate limits
five_hour=$(echo "$input" | jq -r '.rate_limits.five_hour.used_percentage // empty')
seven_day=$(echo "$input" | jq -r '.rate_limits.seven_day.used_percentage // empty')

# Model
model=$(echo "$input" | jq -r '.model.display_name // empty')

parts=()

if [ -n "$model" ]; then
  parts+=("$model")
fi

if [ -n "$used_pct" ]; then
  pct_int=$(printf '%.0f' "$used_pct")
  parts+=("ctx:[$(bar $pct_int)] ${pct_int}%")
fi

if [ -n "$five_hour" ]; then
  pct_int=$(printf '%.0f' "$five_hour")
  parts+=("5h:[$(bar $pct_int)] ${pct_int}%")
fi

if [ -n "$seven_day" ]; then
  pct_int=$(printf '%.0f' "$seven_day")
  parts+=("7d:[$(bar $pct_int)] ${pct_int}%")
fi

if [ ${#parts[@]} -gt 0 ]; then
  printf '%s' "$(IFS=' | '; echo "${parts[*]}")"
fi