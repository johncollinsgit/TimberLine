#!/usr/bin/env python3
"""Prove a small diff is presentation-only; everything else uses full validation."""

import argparse
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess

import tinycss2


CSS_FILES = {
    'resources/css/app.css', 'resources/css/everbranch-workspace.css',
    'resources/css/public-studio.css', 'resources/css/public-story-film.css',
    *[f'resources/css/team-{name}.css' for name in (
        'operations', 'events', 'event-detail', 'event-updates', 'task-cards',
        'work-toolbar', 'universal-search', 'premium', 'assistant')],
}
VIEW_FILES = {
    'resources/views/welcome.blade.php', 'resources/views/platform/promo.blade.php',
    'resources/views/platform/partials/studio-support-nav.blade.php',
    *[f'resources/views/team/{name}.blade.php' for name in (
        'app', 'workspace', 'index', 'operations', 'search', 'premium-toolbar',
        'brand-masthead', 'mobile-unavailable')],
}
COMPANIONS = {'docs/ui/UI_CHANGELOG.md'}
VISUAL_PROPERTIES = {
    'color', 'background-color', 'border-color', 'border-radius',
    'font-size', 'font-weight', 'font-family', 'line-height', 'letter-spacing',
    'text-align', 'text-wrap', 'text-decoration-color',
    'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'margin-inline', 'margin-block', 'padding', 'padding-top', 'padding-right',
    'padding-bottom', 'padding-left', 'padding-inline', 'padding-block',
    'gap', 'row-gap', 'column-gap', 'grid-template-columns', 'grid-template-rows',
    'align-items', 'justify-content', 'flex-basis', 'flex-grow', 'flex-shrink',
}
PALETTE = r'(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(?:50|[1-9]00|950)(?:/[0-9]+)?'
LAYOUT_CLASS = re.compile(
    r'^(?:(?:sm|md|lg|xl|2xl|dark|hover|focus):)*'
    r'(?:-?(?:m[trblxyse]?|p[trblxyse]?|gap|gap-x|gap-y|w|min-w|max-w|h|min-h|max-h|basis)-'
    r'(?:[0-9]+(?:\.[0-9]+)?(?:/[0-9]+)?|auto|full|screen|min|max|fit|none|px|xs|sm|md|lg|xl|[2-9]xl)'
    r'|(?:text|bg|border)-(?:' + PALETTE + r'|white|black|transparent|current|inherit)'
    r'|text-(?:xs|sm|base|lg|xl|[2-9]xl|left|center|right|justify|start|end)'
    r'|font-(?:sans|serif|mono|thin|extralight|light|normal|medium|semibold|bold|extrabold|black)'
    r'|leading-(?:none|tight|snug|normal|relaxed|loose|[0-9]+)'
    r'|tracking-(?:tighter|tight|normal|wide|wider|widest)'
    r'|rounded(?:-(?:none|sm|md|lg|xl|2xl|3xl|full))?'
    r'|border-(?:0|2|4|8)|(?:items|justify)-(?:start|end|center|between|around|evenly|stretch)'
    r'|grid-(?:cols|rows)-[0-9]+|(?:grow|shrink)-[01])$')

DYNAMIC = re.compile(r'{{|}}|{!!|!!}|<\?|\?>|@[a-zA-Z]|\$[a-zA-Z_]|`')
CLASS_ATTRIBUTE = re.compile(r'(?<![\w:-])class\s*=\s*([\"\'])(.*?)\1', re.DOTALL)
VISIBLE_TEXT_TAGS = {'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button',
                     'label', 'li', 'small', 'strong', 'em', 'legend', 'figcaption'}


def canonical(tokens):
    return tinycss2.serialize([token for token in tokens if token.type != 'comment']).strip()


def visual_css(before, after):
    def compare(left, right):
        if len(left) != len(right):
            return False
        for old, new in zip(left, right):
            if old.type == 'error' or new.type == 'error' or old.type != new.type:
                return False
            if canonical([old]) == canonical([new]):
                continue
            if old.type == 'at-rule':
                if (old.lower_at_keyword not in {'media', 'supports', 'layer'}
                        or old.lower_at_keyword != new.lower_at_keyword
                        or canonical(old.prelude) != canonical(new.prelude)
                        or old.content is None or new.content is None):
                    return False
                if not compare(tinycss2.parse_rule_list(old.content, skip_comments=True, skip_whitespace=True),
                               tinycss2.parse_rule_list(new.content, skip_comments=True, skip_whitespace=True)):
                    return False
            elif old.type == 'qualified-rule':
                if canonical(old.prelude) != canonical(new.prelude):
                    return False
                old_declarations = tinycss2.parse_declaration_list(old.content, skip_comments=True, skip_whitespace=True)
                new_declarations = tinycss2.parse_declaration_list(new.content, skip_comments=True, skip_whitespace=True)
                # Keep order, property set and !important flags; only visual values may change.
                if len(old_declarations) != len(new_declarations):
                    return False
                for a, b in zip(old_declarations, new_declarations):
                    if a.type != 'declaration' or b.type != 'declaration':
                        return False
                    if a.lower_name != b.lower_name or a.important != b.important:
                        return False
                    if canonical(a.value) != canonical(b.value):
                        values = canonical(a.value) + canonical(b.value)
                        if (a.lower_name not in VISUAL_PROPERTIES
                                or re.search(r'url\s*\(|expression\s*\(|@|\\', values, re.I)):
                            return False
            else:
                return False
        return True
    try:
        return compare(tinycss2.parse_stylesheet(before, skip_comments=True, skip_whitespace=True),
                       tinycss2.parse_stylesheet(after, skip_comments=True, skip_whitespace=True))
    except (ValueError, TypeError, AttributeError):
        return False


class TemplateEvents(HTMLParser):
    def __init__(self, text):
        super().__init__(convert_charrefs=False)
        self.events = []
        self.stack = []
        self.feed(text)
        self.close()

    def handle_starttag(self, tag, attrs):
        self.events.append(('start', tag, self.get_starttag_text(), tuple(attrs)))
        if tag not in {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'}:
            self.stack.append(tag)

    def handle_startendtag(self, tag, attrs):
        self.events.append(('single', tag, self.get_starttag_text(), tuple(attrs)))

    def handle_endtag(self, tag):
        self.events.append(('end', tag))
        if self.stack and self.stack[-1] == tag:
            self.stack.pop()

    def handle_data(self, data):
        self.events.append(('text', tuple(self.stack), data))

    def handle_comment(self, data):
        self.events.append(('comment', data))

    def handle_decl(self, data):
        self.events.append(('declaration', data))

    def handle_pi(self, data):
        self.events.append(('php', data))

    def handle_entityref(self, name):
        self.events.append(('entity', name))

    def handle_charref(self, name):
        self.events.append(('character', name))


def layout_tag(before, after, old_attrs, new_attrs):
    if ([attr for attr in old_attrs if attr[0] != 'class'] != [attr for attr in new_attrs if attr[0] != 'class']
            or sum(attr[0] == 'class' for attr in old_attrs) != 1
            or sum(attr[0] == 'class' for attr in new_attrs) != 1):
        return False
    old, new = list(CLASS_ATTRIBUTE.finditer(before)), list(CLASS_ATTRIBUTE.finditer(after))
    if len(old) != 1 or len(new) != 1:
        return False
    if CLASS_ATTRIBUTE.sub('class="LAYOUT"', before) != CLASS_ATTRIBUTE.sub('class="LAYOUT"', after):
        return False
    a, b = old[0].group(2), new[0].group(2)
    if dict(old_attrs)['class'] != a or dict(new_attrs)['class'] != b:
        return False
    if DYNAMIC.search(a + b):
        return False
    remaining = lambda value: [word for word in value.split() if not LAYOUT_CLASS.fullmatch(word)]
    return remaining(a) == remaining(b)


def literal_template(before, after):
    try:
        left, right = TemplateEvents(before).events, TemplateEvents(after).events
    except (ValueError, AssertionError):
        return False
    if len(left) != len(right):
        return False
    for old, new in zip(left, right):
        if old == new:
            continue
        if old[:2] != new[:2]:
            return False
        if old[0] in {'start', 'single'}:
            if not layout_tag(old[2], new[2], old[3], new[3]):
                return False
        elif old[0] == 'text':
            stack = old[1]
            if (not stack or stack[-1] not in VISIBLE_TEXT_TAGS
                    or any(tag in {'script', 'style', 'template', 'textarea', 'option'} for tag in stack)
                    or DYNAMIC.search(old[2] + new[2])
                    or re.search(r'[<>{}\[\]$%€£0-9]', old[2] + new[2])):
                return False
        else:
            return False
    return True


def eligible_file(path, before, after):
    if path in CSS_FILES:
        return visual_css(before, after)
    if path in VIEW_FILES:
        return literal_template(before, after)
    return path in COMPANIONS


def git(*args):
    return subprocess.check_output(['git', *args])


def classify(base, head):
    result = {'tier': 'full', 'base': base, 'head': head, 'reason': 'unproven_diff'}
    if not all(re.fullmatch(r'[0-9a-f]{40}', sha or '') for sha in [base, head]):
        return result
    try:
        subprocess.run(['git', 'merge-base', '--is-ancestor', base, head], check=True, capture_output=True)
        entries = git('diff', '--name-status', '--no-renames', '-z', base, head).decode('utf-8').rstrip('\0').split('\0')
        if not entries or len(entries) % 2 or not 1 <= len(entries) // 2 <= 12:
            return result
        files = []
        for status, path in zip(entries[::2], entries[1::2]):
            if status != 'M' or path not in CSS_FILES | VIEW_FILES | COMPANIONS:
                result['reason'] = 'protected_or_unknown_file'
                return result
            for sha in [base, head]:
                mode = git('ls-tree', sha, '--', path).decode().split()[0]
                if mode != '100644':
                    return result
            before, after = [git('show', f'{sha}:{path}').decode('utf-8') for sha in [base, head]]
            if max(len(before), len(after)) > 500_000 or not eligible_file(path, before, after):
                result['reason'] = 'behavior_or_unsupported_edit'
                return result
            files.append(path)
        stats = git('diff', '--numstat', base, head).decode().splitlines()
        if sum(int(line.split('\t')[0]) + int(line.split('\t')[1]) for line in stats) > 200:
            result['reason'] = 'large_diff'
            return result
        return {**result, 'tier': 'presentation', 'reason': 'verified_presentation_only', 'files': files}
    except (subprocess.CalledProcessError, UnicodeError, ValueError, IndexError):
        return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True)
    parser.add_argument('--head', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    report = classify(args.base, args.head)
    Path(args.output).write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report))
