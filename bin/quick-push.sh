#!/bin/bash
set -euo pipefail

# Быстрый коммит и пуш с автогенерацией сообщения по изменённым файлам.
# Запуск: ./bin/quick-push.sh [дополнительный текст]

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "❌ Скрипт нужно запускать внутри git-репозитория."
  exit 1
fi

if ! git status --porcelain | grep -q .; then
  echo "✅ Нечего коммитить: рабочее дерево чистое."
  exit 0
fi

git add -A

commit_summary=$(python3 - <<'PY'
import re
import subprocess
import sys

diff = subprocess.run(
    ["git", "diff", "--cached", "--unified=0", "--no-color"],
    capture_output=True,
    text=True,
    check=True,
).stdout

current_file = None
files = {}
deleted = set()
renamed = []
rename_from = rename_to = None

for line in diff.splitlines():
    if line.startswith("diff --git a/"):
        parts = line.split()
        if len(parts) >= 4:
            current_file = parts[3][2:]  # b/filename
        rename_from = rename_to = None
    elif line.startswith("rename from "):
        rename_from = line.replace("rename from ", "", 1)
    elif line.startswith("rename to "):
        rename_to = line.replace("rename to ", "", 1)
        if rename_from and rename_to:
            renamed.append(f"{rename_from} → {rename_to}")
            current_file = rename_to
    elif line.startswith("+++ "):
        path = line[4:].strip()
        if path == "/dev/null":
            if current_file:
                deleted.add(current_file)
        elif path.startswith("b/"):
            current_file = path[2:]
    elif line.startswith("@@ "):
        match = re.match(r"@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@", line)
        if not match or not current_file:
            continue
        start = int(match.group(1))
        count = int(match.group(2) or "1")
        if count == 0:
            continue
        end = start + count - 1
        rng = f"L{start}" if count == 1 else f"L{start}-{end}"
        files.setdefault(current_file, []).append(rng)

parts = []
for fname, ranges in files.items():
    parts.append(f"{fname} ({', '.join(ranges)})")

for fname in sorted(deleted):
    if fname not in files:
        parts.append(f"{fname} (deleted)")

parts.extend(renamed)

if parts:
    print("; ".join(parts))
PY
)

extra_message="${1:-}"

if [[ -z "$commit_summary" ]]; then
  # fallback: просто перечислим изменённые файлы
  commit_summary=$(git status --short | awk '{print $2}' | paste -sd ", " -)
fi

if [[ -z "$commit_summary" ]]; then
  echo "❌ Не удалось сформировать сообщение коммита."
  exit 1
fi

if [[ -n "$extra_message" ]]; then
  commit_message="auto: ${commit_summary} — ${extra_message}"
else
  commit_message="auto: ${commit_summary}"
fi

git commit -m "$commit_message"

current_branch=$(git branch --show-current)

if [[ -z "$current_branch" ]]; then
  echo "❌ Не удалось определить текущую ветку."
  exit 1
fi

git push origin "$current_branch"

echo "✅ Изменения отправлены: $commit_message"
