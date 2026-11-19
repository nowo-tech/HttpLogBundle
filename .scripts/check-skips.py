#!/usr/bin/env python3
"""Fail unless the skipped tests of a PHPUnit JUnit log are exactly the expected ones (audit VB-5).

Usage: check-skips.py <junit.xml> <Class::method=count> [...]

Each argument names a test method (short class name) and how many of its cases (data sets) must be skipped. Any
other skipped test, or a different count, fails: a server URL that is missing or a test skipped by mistake cannot
pass silently.
"""

import sys
import xml.etree.ElementTree as ET
from collections import Counter


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2

    expected = Counter()
    for argument in sys.argv[2:]:
        name, _, count = argument.rpartition('=')
        expected[name] = int(count)

    skipped = Counter()
    for case in ET.parse(sys.argv[1]).getroot().iter('testcase'):
        if case.find('skipped') is None:
            continue
        cls = (case.get('class') or case.get('classname') or '').rsplit('\\', 1)[-1].rsplit('.', 1)[-1]
        method = (case.get('name') or '').split(' with data set ')[0]
        skipped[f'{cls}::{method}'] += 1

    if skipped == expected:
        print(f'OK: {sum(skipped.values())} expected skips: {dict(skipped)}')
        return 0

    print(f'Unexpected skips. Expected {dict(expected)}, got {dict(skipped)}')
    return 1


if __name__ == '__main__':
    sys.exit(main())
