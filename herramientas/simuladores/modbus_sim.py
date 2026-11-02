# SPDX-License-Identifier: AGPL-3.0-or-later
# Simulador Modbus TCP para pruebas (pip install pymodbus): escucha en 127.0.0.1:5020, unidad 1.
# Registros de entrada: 0-1 TENSION float32 221.5 · 2-3 TEMP float32 -12.25 · 4 CORRIENTE x0.01 = 1234 · 6 int16 -5 · retención 0-1 uint32 3600123
import struct, asyncio
from pymodbus.server import StartAsyncTcpServer
from pymodbus.datastore import ModbusSequentialDataBlock, ModbusDeviceContext, ModbusServerContext
def f32(v): return list(struct.unpack('>HH', struct.pack('>f', v)))
ir = [0]*20
ir[0:2] = f32(221.5)       # TENSION-L1 V
ir[2:4] = f32(-12.25)      # TEMP (float)
ir[4] = 1234               # CORRIENTE x0.01
ir[6] = (-5) & 0xffff      # int16
hr = [0]*20; hr[0:2] = list(struct.unpack('>HH', struct.pack('>I', 3600123)))  # contador uint32
dev = ModbusDeviceContext(ir=ModbusSequentialDataBlock(1, ir), hr=ModbusSequentialDataBlock(1, hr))
ctx = ModbusServerContext(devices={1: dev}, single=False)
asyncio.run(StartAsyncTcpServer(context=ctx, address=("127.0.0.1", 5020)))
