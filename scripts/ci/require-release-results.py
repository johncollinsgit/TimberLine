#!/usr/bin/env python3
"""Require full validation or the proven presentation-only safety path."""

import argparse
import json
import os
import sys


def verify(results, gate, head):
    expected = {'ci': {'plan', 'build', 'php', 'browser'},
                'mysql': {'plan', 'build', 'mysql'}}[gate]
    if not isinstance(results, dict) or set(results) != expected:
        raise ValueError('Missing or unexpected validation prerequisites')
    if any(not isinstance(job, dict) for job in results.values()):
        raise ValueError('Malformed validation prerequisite')
    plan = results['plan']
    outputs = plan.get('outputs', {})
    tier = outputs.get('tier')
    if (plan.get('result') != 'success' or tier not in {'full', 'presentation'}
            or not head or outputs.get('head') != head):
        raise ValueError('The exact revision has no successful validation plan')
    if tier == 'presentation' and (outputs.get('reason') != 'verified_presentation_only'
                                   or outputs.get('verify_presentation') != 'true'):
        raise ValueError('Presentation-only eligibility was not proven')
    for name, job in results.items():
        allowed = {'success'}
        if tier == 'presentation' and name in {'php', 'browser', 'mysql'}:
            allowed = {'skipped'}
        if job.get('result') not in allowed:
            raise ValueError(f'{name} did not finish in its required state')
    return tier


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('gate', choices=['ci', 'mysql'])
    args = parser.parse_args()
    try:
        tier = verify(json.loads(os.environ['JOB_RESULTS']), args.gate, os.environ['EXPECTED_HEAD'])
    except (KeyError, ValueError, TypeError) as error:
        print(f'::error::{error}', file=sys.stderr)
        sys.exit(1)
    message = ('Proven presentation-only diff: build, UI and authorization/tenant smoke checks passed; '
               'backend/schema code unchanged. Full regression/MySQL jobs are not required for this diff.'
               if tier == 'presentation' else 'All full validation prerequisites succeeded.')
    print(message)
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as summary:
            summary.write(f'Validation: **{tier}**. {message}\n')
