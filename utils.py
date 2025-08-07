import threading
import itertools
import sys
import time

def loading_spinner(message="Solving"):
    stop_event = threading.Event()

    def spin():
        for c in itertools.cycle(['|', '/', '-', '\\']):
            if stop_event.is_set():
                break
            sys.stdout.write(f'\r{message}... {c}')
            sys.stdout.flush()
            time.sleep(0.1)
        sys.stdout.write('\r' + ' ' * (len(message) + 5) + '\r')  # Clear line

    thread = threading.Thread(target=spin)
    thread.start()

    return stop_event.set  # return the stop function
