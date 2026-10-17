"""Install the current Chrome PWA through observed Android UI, always scoped to one device."""
import sys,subprocess,xml.etree.ElementTree as ET,re,time
serial=sys.argv[1];adb='/Users/johncollins/Library/Android/sdk/platform-tools/adb';base=[adb,'-P','5038','-s',serial,'shell']
def ui():
 subprocess.run(base+['uiautomator','dump','/sdcard/retail-window.xml'],capture_output=True,check=True)
 return ET.fromstring(subprocess.check_output(base+['cat','/sdcard/retail-window.xml']))
def tap(n):
 p=list(map(int,re.findall(r'\d+',n.get('bounds'))));subprocess.run(base+['input','tap',str((p[0]+p[2])//2),str((p[1]+p[3])//2)],check=True)
def nodes(r):return [n for n in r.iter('node') if n.get('text') or n.get('content-desc')]
r=ui();button=next((n for n in r.iter('node') if n.get('resource-id','').endswith('menu_button')),None)
if button is None:
 print('Chrome toolbar is hidden; showing it with an upward-page gesture.');subprocess.run(base+['input','swipe','500','650','500','1500','450'],check=True);r=ui();button=next((n for n in r.iter('node') if n.get('resource-id','').endswith('menu_button')),None)
if button is None:
 print([(n.get('text'),n.get('content-desc'),n.get('bounds')) for n in nodes(r)]);sys.exit(2)
tap(button);r=ui();button=next((n for n in nodes(r) if n.get('text') in ['Install app','Add to Home screen','Add to home screen']),None)
if button is None:print([(n.get('text'),n.get('bounds')) for n in nodes(r)]);sys.exit(3)
tap(button);r=ui();print([(n.get('text'),n.get('bounds')) for n in nodes(r)])
button=next((n for n in nodes(r) if n.get('text') in ['Install','Add']),None)
if button is not None:
 tap(button);time.sleep(2);r=ui();print('After install:',[(n.get('text'),n.get('bounds')) for n in nodes(r)])
 # Some launchers ask for a second confirmation before placing a shortcut.
 button=next((n for n in nodes(r) if n.get('text') in ['Add','Add automatically','Add to Home screen']),None)
 if button is not None:tap(button);print('Confirmed the launcher shortcut.')
