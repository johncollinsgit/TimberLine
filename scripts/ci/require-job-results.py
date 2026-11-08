#!/usr/bin/env python3
"""Keep required check names stable while their work runs on isolated runners."""

import json
import os
import sys


def failures(results, expected):
    if not isinstance(results, dict) or not expected:
        raise ValueError('Expected named prerequisite jobs')
    if set(results) != set(expected):
        raise ValueError('Prerequisite job set differs from the required job set')
    return [name for name in expected
            if not isinstance(results[name], dict)
            or results[name].get('result') != 'success']


def main():
    try:
        rejected = failures(json.loads(os.environ['JOB_RESULTS']), sys.argv[1:])
    except (KeyError, ValueError, TypeError) as error:
        print(f'::error::Invalid validation results: {error}', file=sys.stderr)
        return 1
    if rejected:
        print(f'::error::Validation did not succeed: {", ".join(rejected)}', file=sys.stderr)
        return 1
    print('All required validation jobs succeeded.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
