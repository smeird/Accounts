#!/usr/bin/env python3
"""Render maintained wiki/ Markdown into a separate GitHub Wiki checkout.

Use --check to verify an existing mirror. This command never commits or pushes.
"""
import argparse
from pathlib import Path
import re
import sys
from urllib.parse import unquote

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'wiki'
REPO = 'https://github.com/smeird/Accounts/blob/main/'
WIKI = 'https://github.com/smeird/Accounts/wiki/'
LINK = re.compile(r'(!?\[[^\]\n]*\]\()([^\s)]+)(\))')


def render(path):
    def replace(match):
        target = match[2]
        if target.startswith(('https://', 'http://', '#', 'mailto:')):
            return match[0]
        name, separator, anchor = target.partition('#')
        resolved = (path.parent / unquote(name)).resolve()
        if not resolved.is_relative_to(ROOT) or not resolved.exists():
            raise ValueError(f'{path.relative_to(ROOT)}: missing link {target}')
        if resolved.parent == SOURCE and resolved.suffix == '.md':
            destination = WIKI + resolved.stem
        else:
            destination = REPO + resolved.relative_to(ROOT).as_posix()
        if separator:
            destination += '#' + anchor
        return match[1] + destination + match[3]
    return LINK.sub(replace, path.read_text())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('checkout', type=Path, help='existing Accounts.wiki Git checkout')
    parser.add_argument('--check', action='store_true', help='report drift without writing')
    args = parser.parse_args()
    destination = args.checkout.resolve()
    if not (destination / '.git').exists() or destination == SOURCE:
        parser.error('provide a separate existing GitHub Wiki checkout')
    pages = {path.name: render(path) for path in sorted(SOURCE.glob('*.md'))}
    differences = []
    for name, content in pages.items():
        output = destination / name
        if not output.exists() or output.read_text() != content:
            differences.append(name)
            if not args.check:
                output.write_text(content)
    # Preserve unknown pages: never silently delete someone else's Wiki content.
    extras = sorted(path.name for path in destination.glob('*.md') if path.name not in pages)
    if differences:
        print(('Out of sync: ' if args.check else 'Updated: ') + ', '.join(differences))
    if extras:
        print('Wiki-only pages retained for manual review: ' + ', '.join(extras))
    if args.check and (differences or extras):
        return 1
    print(f'{len(pages)} maintained pages validated' + (' and matched.' if args.check else '.'))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except ValueError as error:
        sys.exit(str(error))
