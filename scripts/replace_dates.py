import shutil
from pathlib import Path

p = Path(r"c:\xampp\htdocs\tkn\teawkanna (2).sql")
if not p.exists():
    print("File not found:", p)
    raise SystemExit(1)

bak = p.with_suffix('.sql.bak')
shutil.copyfile(p, bak)

text = p.read_text(encoding='utf-8')
old = "'0000-00-00 00:00:00', '0000-00-00 00:00:00'"
new = "'2026-01-01 00:00:00', '2026-12-31 23:59:59'"
count = text.count(old)
if count == 0:
    print('No occurrences found.')
else:
    text = text.replace(old, new)
    p.write_text(text, encoding='utf-8')
    print(f'Replaced {count} occurrences. Backup at: {bak}')
