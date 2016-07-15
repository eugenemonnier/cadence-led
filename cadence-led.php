#!/usr/bin/python2.7
"""
    Code based on:
        https://github.com/mvillalba/python-ant/blob/develop/demos/ant.core/03-basicchannel.py
    in the python-ant repository and
        https://github.com/tomwardill/developerhealth
    by Tom Wardill and 
    	https://github.com/haotianwooo/ant_raspberry/blob/master/only_cadence.py
    by haotianwooo
    
"""


from bibliopixel.led import *
from bibliopixel.animation import BaseStripAnim
from bibliopixel.drivers.LPD8806 import *
from bibliopixel import LEDStrip
import bibliopixel.colors as colors

numLeds = 50
driver = DriverLPD8806(numLeds, c_order = ChannelOrder.BRG, use_py_spi = True, SPISpeed = 8 )
led = LEDStrip(driver)
lightspeed = 30


class ColorChase(BaseStripAnim):
    """Chase one pixel down the strip."""

    def __init__(self, led, color, width=1, start=0, end=-1):
        super(ColorChase, self).__init__(led, start, end)
        self._color = color
        self._width = width

    def step(self, amt = 1):
        self._led.all_off() #because I am lazy

        for i in range(self._width):
            self._led.set(self._start + self._step + i, self._color)

        self._step += amt
        overflow = (self._start + self._step) - self._end
        if overflow >= 0:
            self._step = overflow


            
anim = ColorChase(led,(150,0,0),5)      

anim.run(threaded=True) 
anim._internalDelay = 250






import sys
import time
import serial
from ant.core import driver, node, event, message, log
from ant.core.constants import CHANNEL_TYPE_TWOWAY_RECEIVE, TIMEOUT_NEVER
ser=serial.Serial()
ser.port='/dev/ttyAMA0'
ser.open()

class HRM(event.EventCallback):

    def __init__(self, serial, netkey):
        self.serial = serial
        self.netkey = netkey
        self.antnode = None
        self.channel = None
        self.cadence_cnt = 0
        self.cadence_time = 0
        self.cadence_cnt_old = -1
        self.cadence_time_old = -1
        self.cadence = 0
        self.speed_cnt=0
        self.speed_time=0
        self.speed_cnt_old=-1
        self.speed_time_old=-1
        self.speed=0

    def start(self):
        print("starting node")
        self._start_antnode()
        self._setup_channel()
        self.channel.registerCallback(self)
        print("start listening for hr events")

    def stop(self):
        if self.channel:
            self.channel.close()
            self.channel.unassign()
        if self.antnode:
            self.antnode.stop()

    def __enter__(self):
        return self

    def __exit__(self, type_, value, traceback):
        self.stop()

    def _start_antnode(self):
        stick = driver.USB2Driver(self.serial)
        self.antnode = node.Node(stick)
        self.antnode.start()

    def _setup_channel(self):
        key = node.NetworkKey('N:ANT+', self.netkey)
        self.antnode.setNetworkKey(0, key)
        self.channel = self.antnode.getFreeChannel()
        self.channel.name = 'C:HRM'
        self.channel.assign('N:ANT+', CHANNEL_TYPE_TWOWAY_RECEIVE)
        self.channel.setID(121, 0, 0)
        #self.channel.setID(121, 20705, 0)
        self.channel.setSearchTimeout(TIMEOUT_NEVER)
        self.channel.setPeriod(8086)
        self.channel.setFrequency(57)
        self.channel.open()

    def process(self, msg):
        if isinstance(msg, message.ChannelBroadcastDataMessage):
            self.cadence_cnt = int(ord(msg.payload[3]))+256*ord(msg.payload[4])
            self.cadence_time = ord(msg.payload[1])+256*ord(msg.payload[2])
            if self.cadence_cnt == self.cadence_cnt_old:
                return
            if self.cadence_time == self.cadence_time_old:
                return
            if self.cadence_cnt_old == -1:
                self.cadence_cnt_old = self.cadence_cnt
                self.cadence_time_old = self.cadence_time
                return
            if self.cadence_cnt < self.cadence_cnt_old:
                self.cadence_cnt += 65536
            if self.cadence_time < self.cadence_time_old:
                self.cadence_time += 65536
            self.cadence=(self.cadence_cnt-self.cadence_cnt_old)*1024*60.0/(self.cadence_time-self.cadence_time_old)
            #print "cadence="+str(self.cadence)
            if self.cadence_time > 65536:
                self.cadence_time -= 65536
            if self.cadence_cnt > 65536:
                self.cadence_cnt -= 65536
            self.cadence_cnt_old = self.cadence_cnt
            self.cadence_time_old = self.cadence_time

SERIAL = '/dev/ttyUSB0'
NETKEY = 'B9A521FBBD72C345'.decode('hex')

hrm = HRM(serial=SERIAL, netkey=NETKEY)
hrm.start()
stop_cnt=0
stop_cadence_pre=0

while True:
        try:
            time.sleep(0.3)
            
            # virtual flywheel / graceful brake
            if hrm.cadence < (0.9*lightspeed):
                lightspeed = int((0.9*lightspeed))
            else:
                lightspeed = int(hrm.cadence)
            if lightspeed < 30:
				lightspeed = 30
            #print "lightspeed:"+str(lightspeed)				
            
            # set colours
            if lightspeed < 31:
                ledr = 255
                ledg = 0
                ledb = 0
            elif lightspeed < 41:
                ledr = 255
                ledg = int((lightspeed/41) * 255)
                ledb = 0
            elif lightspeed < 70:
                ledr = int((1-(lightspeed/70))*255)
                ledg = 255
                ledb = 0
            elif lightspeed < 100:
                ledr = 0
                ledg = 255
                ledb = int((lightspeed/100) * 255)
            elif lightspeed < 110:
                ledr = int((lightspeed/110) * 255)
                ledg = 255
                ledb = 255
            else:
                ledr = 255
                ledg = 255
                ledb = 255
            
            print "ledr:"+str(ledr)              
            print "ledg:"+str(ledg)             
            print "ledb:"+str(ledb) 
                        
            if hrm.cadence<>0:
                hrm.ledspeed = int((1000/lightspeed))
                anim._internalDelay = (hrm.ledspeed)
                anim._color = (ledr,ledg,ledb)
                print "ledspeed:"+str(hrm.ledspeed) 
            else:
                anim._internalDelay = 250
                anim._color = (ledr,ledg,ledb)
            
            print "cadence:"+str(hrm.cadence)

            
        except KeyboardInterrupt:
            sys.exit(0)
