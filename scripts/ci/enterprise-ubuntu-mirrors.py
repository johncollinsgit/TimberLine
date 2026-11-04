"""Use the same verified official-mirror setup as primary CI."""
from pathlib import Path
import runpy

runpy.run_path(str(Path(__file__).with_name('ubuntu-official-mirrors.py')), run_name='__main__')
